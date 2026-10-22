<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class source
{
    public static function enable(\stdClass $quiz): void
    {
        global $DB,$USER,$CFG;
        require_once($CFG->libdir . '/gradelib.php');
        if (state::role() !== 'source' || state::$internal) {
            return;
        }
        $map = state::map((int)$quiz->id);
        if (empty($quiz->examsync_enabled)) {
            if ($map) {
                throw new \moodle_exception('cannotdisable', 'local_examsync');
            }
            return;
        }
        if ($map) {
            return;
        }
        if ($DB->record_exists('quiz_attempts', ['quiz' => $quiz->id,'preview' => 0])) {
            throw new \moodle_exception('existingattempts', 'local_examsync');
        }
        $item = \grade_item::fetch(['itemtype' => 'mod','itemmodule' => 'quiz','iteminstance' => $quiz->id,'itemnumber' => 0]);
        if ($item && ($item->is_locked() || $DB->record_exists_select(
            'grade_grades',
            'itemid = ? AND (finalgrade IS NOT NULL OR overridden <> 0)',
            [$item->id]
        ))) {
            throw new \moodle_exception('existinggrades', 'local_examsync');
        }
        $cm = !empty($quiz->coursemodule) ? get_coursemodule_from_id('quiz', $quiz->coursemodule, 0, false, MUST_EXIST) :
          get_coursemodule_from_instance('quiz', $quiz->id, 0, false, MUST_EXIST);
        require_capability('local/examsync:manage', \context_module::instance($cm->id));
        $map = (object)['uuid' => \core\uuid::generate(),'side' => 'source','courseid' => $cm->course,'cmid' => $cm->id,
         'quizid' => $quiz->id,'revision' => 0,'applied' => 0,'state' => 'draft','frozen' => 0,'actorid' => $USER->id,
         'origvisible' => $cm->visible,'timecreated' => time(),'timemodified' => time()];
        $map->id = $DB->insert_record('local_examsync_map', $map);
        state::dirty($map->id);
    }
    public static function suppress_author_grade(\stdClass $map): void
    {
        global $CFG;
        require_once($CFG->libdir.'/gradelib.php');
        $previous = state::$internal;
        state::$internal = true;
        try {
            grade_update(
                'local_examsync',
                $map->courseid,
                'mod',
                'quiz',
                $map->quizid,
                0,
                null,
                ['gradetype' => GRADE_TYPE_NONE,'hidden' => 1]
            );
        } finally {
            state::$internal = $previous;
        }
    }
    public static function publish(\stdClass $map, array $data): void
    {
        global $CFG,$DB;
        if (!has_capability('local/examsync:manage', \context_module::instance($map->cmid), $map->actorid)) {
            throw new \RuntimeException('Publishing teacher no longer has permission');
        }
        require_once($CFG->dirroot.'/course/modlib.php');
        require_once($CFG->dirroot.'/mod/lti/lib.php');
        require_once($CFG->dirroot.'/mod/lti/locallib.php');
        require_once($CFG->libdir.'/gradelib.php');
        $typeid = (int)get_config('local_examsync', 'ltitypeid');
        $type = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
        if ($type->ltiversion !== '1.3.0') {
            throw new \RuntimeException('A registered LTI 1.3 tool type is required');
        }
        $quiz = $DB->get_record('quiz', ['id' => $map->quizid], '*', MUST_EXIST);
        // Quiz grades are decimals; mod_lti.grade is an integer on every database.
        // Accept values such as "10.00000" without silently truncating fractional maxima.
        $maxgrade = (float)$quiz->grade;
        if (!is_finite($maxgrade) || $maxgrade < 0 || floor($maxgrade) !== $maxgrade) {
            throw new \RuntimeException('Remote exam maximum grade must be a non-negative whole number for Moodle LTI');
        }
        $cm = get_coursemodule_from_id('quiz', $map->cmid, 0, false, MUST_EXIST);
        $course = get_course($map->courseid);
        $section = $DB->get_field('course_sections', 'section', ['id' => $cm->section], MUST_EXIST);
        $lti = (object)['course' => $course->id,'name' => $quiz->name,'intro' => $quiz->intro,'introformat' => $quiz->introformat,
         'typeid' => $typeid,'toolurl' => rtrim(get_config('local_examsync', 'peerurl'), '/').'/enrol/lti/launch.php',
         'instructorcustomparameters' => 'id='.$data['tooluuid'],'instructorchoiceacceptgrades' => LTI_SETTING_ALWAYS,
         'instructorchoicesendname' => LTI_SETTING_ALWAYS,'instructorchoicesendemailaddr' => LTI_SETTING_ALWAYS,
         'launchcontainer' => LTI_LAUNCH_CONTAINER_WINDOW,'grade' => (int)$maxgrade,
         'modulename' => 'lti','module' => $DB->get_field('modules', 'id', ['name' => 'lti'], MUST_EXIST),'section' => $section,
         'visible' => $map->origvisible,'visibleoncoursepage' => 1,'availability' => $cm->availability,'groupmode' => 0,
         'groupingid' => 0,'completion' => 0,'cmidnumber' => 'examsync-'.$map->uuid];
        $previous = state::$internal;
        state::$internal = true;
        try {
            if (!$map->ltiid) {
                $lti = add_moduleinfo($lti, $course);
                $map->ltiid = $lti->id;
                $map->lticmid = $lti->coursemodule;
                $author = \grade_item::fetch(['itemtype' => 'mod','itemmodule' => 'quiz','iteminstance' => $map->quizid,'itemnumber' => 0]);
                $canonical = \grade_item::fetch(['itemtype' => 'mod','itemmodule' => 'lti','iteminstance' => $map->ltiid,'itemnumber' => 0]);
                if ($author && $canonical) {
                    $canonical->set_parent($author->categoryid, false);
                    foreach (['aggregationcoef','aggregationcoef2','weightoverride','gradepass','display','decimals'] as $field) {
                        $canonical->$field = $author->$field;
                    }
                    $canonical->update();
                }
            } else {
                $lti->instance = $map->ltiid;
                $lti->coursemodule = $map->lticmid;
                $lticm = get_coursemodule_from_id('lti', $map->lticmid, 0, false, MUST_EXIST);
                $lti->visible = $lticm->visible;
                update_moduleinfo($lticm, $lti, $course);
            }
            // Hide authoring activity after the usable launch link exists; prevent_access also blocks direct attempts.
            set_coursemodule_visible($map->cmid, 0);
            self::suppress_author_grade($map);
            $map->applied = $data['revision'];
            $map->state = 'ready';
            $map->error = null;
            $map->timemodified = time();
            $map->metadata = json_encode($data, JSON_THROW_ON_ERROR);
            $DB->update_record('local_examsync_map', $map);
        } finally {
            state::$internal = $previous;
        }
    }
    public static function authorize(array $data): array
    {
        global $DB;
        $map = $DB->get_record('local_examsync_map', ['uuid' => protocol::uuid($data['uuid']),'side' => 'source'], '*', MUST_EXIST);
        $lock = state::lock('map:'.$map->id);
        try {
            $map = $DB->get_record('local_examsync_map', ['id' => $map->id], '*', MUST_EXIST);
            $userid = (int)($data['userid'] ?? 0);
            $user = \core_user::get_user($userid, '*', MUST_EXIST);
            if ($user->deleted || $user->suspended || $map->state !== 'ready' || (int)$map->revision !== (int)$map->applied ||
               (int)$data['revision'] !== (int)$map->applied) {
                return ['allowed' => false];
            }
            $ctx = \context_course::instance($map->courseid);
            $cm = get_fast_modinfo($map->courseid, $userid)->get_cm($map->lticmid);
            $allowed = is_enrolled($ctx, $user, '', true) && $cm->uservisible && has_capability('mod/lti:view', $cm->context, $user) &&
                has_capability('mod/quiz:attempt', \context_module::instance($map->cmid), $user);
            if ($allowed) {
                $DB->set_field('local_examsync_map', 'frozen', 1, ['id' => $map->id]);
            }
            return ['allowed' => $allowed];
        } finally {
            $lock->release();
        }
    }
}
