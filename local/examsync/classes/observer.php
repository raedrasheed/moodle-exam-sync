<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class observer
{
    public static function content(\core\event\base $event): void
    {
        global $DB;
        if (state::$internal || state::role() !== 'source') {
            return;
        }
        $cmid = $event->contextlevel === CONTEXT_MODULE ? $event->contextinstanceid : 0;
        if ($event->objecttable === 'course_modules') {
            $cmid = $event->objectid;
        }
        if (!$cmid) {
            return;
        }
        $m = $DB->get_record('local_examsync_map', ['cmid' => $cmid,'side' => 'source']);
        if (!$m) {
            return;
        }
        if ($event->eventname === '\\core\\event\\course_module_deleted') {
            $m->state = 'archived';
            $m->timemodified = time();
            $DB->update_record('local_examsync_map', $m);
            return;
        }
        source::suppress_author_grade($m);
        state::dirty($m->id);
    }
    public static function question(\core\event\base $event): void
    {
        global $DB;
        if (state::$internal || state::role() !== 'source') {
            return;
        }
        $mapids = $DB->get_fieldset_select(
            'local_examsync_dep',
            'mapid',
            '(kind=? AND itemid=?) OR (kind=? AND itemid=?)',
            ['question',(int)$event->objectid,'context',(int)$event->contextid]
        );
        // Saving an edit creates a new question ID, but keeps the bank entry stable.
        // Resolve the entry before the next export refreshes the question dependencies.
        if ($event->objecttable === 'question') {
            $entryid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $event->objectid]);
            if ($entryid) {
                $mapids = array_merge($mapids, $DB->get_fieldset_select(
                    'local_examsync_dep',
                    'mapid',
                    'kind=? AND itemid=?',
                    ['entry',(int)$entryid]
                ));
            }
        }
        // Category structure and tag changes can change random selection without changing a quiz slot.
        if (str_contains($event->eventname, 'tag_')) {
            $mapids = array_merge($mapids, $DB->get_fieldset_select('local_examsync_dep', 'mapid', 'kind=?', ['context']));
        }
        foreach (array_unique($mapids) as $id) {
            state::dirty((int)$id);
        }
    }
    public static function membership(\core\event\base $event): void
    {
        global $DB;
        if (state::$internal || state::role() !== 'source') {
            return;
        }
        $courseid = (int)$event->courseid;
        if ($courseid) {
            $maps = $DB->get_records('local_examsync_map', ['side' => 'source','courseid' => $courseid]);
        } else {
            $userid = (int)($event->relateduserid ?: $event->objectid);
            $courses = enrol_get_all_users_courses($userid, false, 'id');
            $maps = [];
            foreach ($courses as $c) {
                $maps += $DB->get_records('local_examsync_map', ['side' => 'source','courseid' => $c->id]);
            }
        }
        foreach ($maps as $m) {
            self::queue_membership((int)$m->id);
        }
    }
    public static function queue_membership(int $mapid): void
    {
        global $DB;
        $lock = state::lock('map:'.$mapid);
        try {
            $m = $DB->get_record('local_examsync_map', ['id' => $mapid], '*', MUST_EXIST);
            if (in_array($m->state, ['archived','privacyblocked'], true)) {
                return;
            }
            $m->runtimever++;
            $DB->update_record('local_examsync_map', $m);
            state::enqueue('runtime', $m->id, $m->runtimever, [], 5);
        } finally {
            $lock->release();
        }
    }
    public static function grade(\core\event\base $event): void
    {
        global $DB;
        if (state::$internal || state::role() !== 'target') {
            return;
        }
        $item = $DB->get_record('grade_items', ['id' => $event->other['itemid']]);
        if (!$item || $item->itemmodule !== 'quiz' || $item->itemnumber != 0) {
            return;
        }
        $m = state::map((int)$item->iteminstance);
        if ($m && $m->side === 'target') {
            grades::queue($m, (int)$event->relateduserid);
        }
    }
    public static function attempt(\mod_quiz\event\attempt_started $event): void
    {
        global $DB;
        if (state::role() !== 'target') {
            return;
        }
        $attempt = $DB->get_record('quiz_attempts', ['id' => $event->objectid]);
        if (!$attempt || $attempt->preview) {
            return;
        }
        $m = state::map((int)$attempt->quiz);
        if (!$m) {
            return;
        }
        $DB->set_field('local_examsync_map', 'frozen', 1, ['id' => $m->id]);
        state::enqueue('frozen', $m->id);
        attempt_guard::release();
    }
    public static function gradeitem(\core\event\base $event): void
    {
        global $DB;
        if (state::$internal) {
            return;
        }
        $item = $DB->get_record('grade_items', ['id' => $event->objectid]);
        if (!$item || $item->itemmodule !== 'quiz') {
            return;
        }
        $m = state::map((int)$item->iteminstance);
        if (!$m) {
            return;
        }
        if (state::role() === 'source') {
            if ((int)$item->gradetype !== GRADE_TYPE_NONE) {
                source::suppress_author_grade($m);
            }
        } else {
            foreach ($DB->get_fieldset_select('grade_grades', 'userid', 'itemid=?', [$item->id]) as $uid) {
                grades::queue($m, (int)$uid);
            }
        }
    }
}

