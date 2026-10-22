<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class target
{
    public static function course(array $data): int
    {
        global $DB,$CFG;
        require_once($CFG->dirroot.'/course/lib.php');
        $lock = state::lock('course:'.(int)$data['id']);
        try {
            $link = $DB->get_record('local_examsync_course', ['sourceid' => (int)$data['id']]);
            if ($link) {
                update_course((object)['id' => $link->courseid,'fullname' => $data['fullname']]);
                return (int)$link->courseid;
            }
            $course = create_course((object)['fullname' => $data['fullname'],'shortname' => 'EXAM-'.(int)$data['id'].'-'.substr(hash('sha256', get_config('local_examsync', 'peerid')), 0, 8),
             'idnumber' => 'examsync-'.(int)$data['id'],'category' => (int)(get_config('local_examsync', 'categoryid') ?: 1),
             'format' => 'topics','numsections' => 1,'newsitems' => 0,'summary' => '','visible' => 1]);
            $DB->insert_record('local_examsync_course', (object)['sourceid' => (int)$data['id'],'courseid' => $course->id]);
            return $course->id;
        } finally {
            $lock->release();
        }
    }
    public static function publish_tool(\stdClass $map): array
    {
        global $DB;
        get_auth_plugin('lti');
        $plugin = enrol_get_plugin('lti');
        if (!$plugin || !enrol_is_enabled('lti')) {
            throw new \RuntimeException('Enable LTI enrolment');
        }
        $contextid = \context_module::instance($map->cmid)->id;
        $fields = ['contextid' => $contextid,'ltiversion' => 'LTI-1p3','status' => ENROL_INSTANCE_ENABLED,
         'name' => 'ExamSync '.$map->uuid,'institution' => '','lang' => 'en','timezone' => '99','city' => '','country' => '',
         'maxenrolled' => 0,'maildisplay' => 0,'gradesync' => 0,'gradesynccompletion' => 0,'membersync' => 0,'membersyncmode' => 0,
         'roleinstructor' => (int)get_config('local_examsync', 'instructorrole'),'rolelearner' => (int)get_config('local_examsync', 'learnerrole'),
         'provisioningmodeinstructor' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY,
         'provisioningmodelearner' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY];
        if ($map->toolid) {
            $tool = $DB->get_record('enrol_lti_tools', ['id' => $map->toolid], '*', MUST_EXIST);
            $instance = $DB->get_record('enrol', ['id' => $tool->enrolid], '*', MUST_EXIST);
            $fields['toolid'] = $tool->id;
            $fields['uuid'] = $tool->uuid;
            $plugin->update_instance($instance, (object)$fields);
        } else {
            $enrolid = $plugin->add_instance(get_course($map->courseid), $fields);
            $tool = $DB->get_record('enrol_lti_tools', ['enrolid' => $enrolid], '*', MUST_EXIST);
        }
        return ['toolid' => (int)$tool->id,'tooluuid' => $tool->uuid];
    }
    public static function restore(\stdClass $map, \stdClass $op): array
    {
        global $DB,$CFG;
        require_once($CFG->dirroot.'/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot.'/course/lib.php');
        require_once($CFG->dirroot.'/mod/quiz/lib.php');
        require_once($CFG->dirroot.'/mod/quiz/locallib.php');
        $meta = json_decode($op->payload, true, 512, JSON_THROW_ON_ERROR);
        if ($op->state === 'done') {
            return json_decode($op->result, true, 512, JSON_THROW_ON_ERROR);
        }
        $hasattempts = $map->quizid && $DB->record_exists('quiz_attempts', ['quiz' => $map->quizid,'preview' => 0]);
        if ((int)$op->revision !== (int)$map->revision || !protocol::may_replace((int)$map->applied, (int)$op->revision, (bool)$hasattempts, (bool)$map->frozen)) {
            throw new \RuntimeException('Revision is stale or exam attempts have frozen its content');
        }
        foreach ($meta['qtypes'] as $qtype) {
            if (!\core_component::get_plugin_directory('qtype', $qtype)) {
                throw new \RuntimeException('Missing question type: '.$qtype);
            }
        }
        $dir = package::directory($map->uuid, (int)$op->revision);
        $archive = $dir.'/exam.mbz';
        if (!is_file($archive) || filesize($archive) !== $meta['size'] || !hash_equals($meta['sha256'], hash_file('sha256', $archive))) {
            throw new \RuntimeException('Package checksum mismatch');
        }
        $map->courseid = self::course($meta['course']);
        $courselock = state::lock('restore-course:'.$map->courseid);
        try {
            $stamp = 'examsync:'.$map->uuid.':'.$op->revision;
            // An interrupted restore is never promoted. Clean up only this uniquely tagged, unattempted staging activity.
            foreach ($DB->get_records('course_modules', ['course' => $map->courseid,'idnumber' => $stamp]) as $stale) {
                if ($DB->record_exists('quiz_attempts', ['quiz' => $stale->instance,'preview' => 0])) {
                    throw new \RuntimeException('Staging activity unexpectedly has attempts');
                }
                course_delete_module($stale->id);
            }
            $folder = 'examsync-'.bin2hex(random_bytes(12));
            $extract = $CFG->tempdir.'/backup/'.$folder;
            if (!get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($archive, $extract)) {
                throw new \RuntimeException('Extraction failed');
            }
            $runtime = json_decode(file_get_contents($extract.'/examsync-runtime.json'), true, 512, JSON_THROW_ON_ERROR);
            $rc = new \restore_controller(
                $folder,
                $map->courseid,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                get_admin()->id,
                \backup::TARGET_EXISTING_ADDING
            );
            try {
                if (!$rc->execute_precheck()) {
                    throw new \RuntimeException('Restore precheck did not pass');
                }
                $rc->execute_plan();
            } finally {
                $rc->destroy();
            }
            $cm = $DB->get_record('course_modules', ['course' => $map->courseid,'idnumber' => $stamp], '*', MUST_EXIST);
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            if ($DB->count_records('quiz_slots', ['quizid' => $quiz->id]) !== (int)$meta['slotcount']) {
                throw new \RuntimeException('Restored slot count mismatch');
            }
            // Backup never enables local authoring settings. The target mapping provides the access rule.
            $oldcm = $map->cmid;
            if ($oldcm && $oldcm != $cm->id) {
                set_coursemodule_visible($oldcm, 0);
            }
            $map->cmid = $cm->id;
            $map->quizid = $quiz->id;
            $map->revision = $op->revision;
            $tool = self::publish_tool($map);
            $map->toolid = $tool['toolid'];
            $map->state = 'staging';
            $DB->update_record('local_examsync_map', $map);
            membership::apply($map, $runtime);
            \mod_quiz\grade_calculator::create(\mod_quiz\quiz_settings::create($quiz->id))->recompute_quiz_sumgrades();
            $quiz->visible = 1;
            quiz_grade_item_update($quiz);
            if ($oldcm && $oldcm != $cm->id) {
                set_coursemodule_visible($oldcm, 0);
            }
            $map->applied = $op->revision;
            $map->state = 'ready';
            $map->error = null;
            $map->timemodified = time();
            $DB->update_record('local_examsync_map', $map);
            set_coursemodule_visible($cm->id, 1);
            $result = ['uuid' => $map->uuid,'revision' => (int)$map->applied,'tooluuid' => $tool['tooluuid'],
             'targetcmid' => (int)$cm->id,'targetcourseid' => (int)$map->courseid];
            $op->state = 'done';
            $op->result = json_encode($result, JSON_THROW_ON_ERROR);
            $DB->update_record('local_examsync_op', $op);
            remove_dir($extract);
            return $result;
        } finally {
            $courselock->release();
        }
    }
}
