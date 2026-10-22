<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class grades
{
    public static function queue(\stdClass $map, int $userid): void
    {
        global $DB;
        $lock = state::lock('grade:'.$map->id.':'.$userid);
        try {
            $g = $DB->get_record('local_examsync_grade', ['mapid' => $map->id,'userid' => $userid]);
            if (!$g) {
                $g = (object)['mapid' => $map->id,'userid' => $userid,'sequence' => 0,'applied' => 0];
                $g->id = $DB->insert_record('local_examsync_grade', $g);
            }
            $g->sequence++;
            $DB->update_record('local_examsync_grade', $g);
            state::enqueue('grade', $map->id, $g->sequence, ['userid' => $userid]);
        } finally {
            $lock->release();
        }
    }
    public static function send(\stdClass $m, int $userid): void
    {
        global $DB,$CFG;
        require_once($CFG->libdir.'/gradelib.php');
        $link = $DB->get_record('local_examsync_user', ['userid' => $userid], '*', MUST_EXIST);
        $g = $DB->get_record('local_examsync_grade', ['mapid' => $m->id,'userid' => $userid], '*', MUST_EXIST);
        if ($g->applied >= $g->sequence) {
            return;
        }
        $item = \grade_item::fetch(['itemtype' => 'mod','itemmodule' => 'quiz','iteminstance' => $m->quizid,'itemnumber' => 0]);
        if (!$item) {
            throw new \RuntimeException('Missing exam grade item');
        }
        if ((int)$item->gradetype === GRADE_TYPE_NONE || (float)$item->grademax <= 0) {
            $DB->set_field('local_examsync_grade', 'applied', $g->sequence, ['id' => $g->id]);
            return;
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id,'userid' => $userid]);
        $result = client::call('grade', ['uuid' => $m->uuid,'revision' => (int)$m->applied,'userid' => (int)$link->sourceid,
         'sequence' => (int)$g->sequence,'grade' => !$grade || $grade->finalgrade === null ? null : (float)$grade->finalgrade,
         'grademax' => (float)$item->grademax,'hidden' => (int)($grade->hidden ?? 0),'itemhidden' => (int)$item->hidden,
         'dategraded' => (int)($grade->timemodified ?? time())]);
        if (empty($result['accepted'])) {
            throw new \RuntimeException('Grade was not accepted');
        }
        $DB->set_field('local_examsync_grade', 'applied', $g->sequence, ['id' => $g->id]);
    }
    public static function receive(\stdClass $m, array $d): array
    {
        global $DB,$CFG;
        require_once($CFG->libdir.'/gradelib.php');
        if ((int)$d['revision'] !== (int)$m->applied || !$m->ltiid) {
            throw new \RuntimeException('Grade refers to an unapproved revision');
        }
        $userid = (int)$d['userid'];
        $seq = protocol::revision($d['sequence'] ?? null);
        $user = \core_user::get_user($userid, 'id,deleted', MUST_EXIST);
        if ($user->deleted) {
            throw new \RuntimeException('Deleted recipient');
        }
        $value = $d['grade'];
        $max = (float)$d['grademax'];
        if (!is_finite($max) || $max <= 0 || ($value !== null && (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > $max))) {
            throw new \RuntimeException('Invalid grade');
        }
        $g = $DB->get_record('local_examsync_grade', ['mapid' => $m->id,'userid' => $userid]);
        if ($g && $g->applied >= $seq) {
            return ['accepted' => true];
        }
        $item = \grade_item::fetch(['itemtype' => 'mod','itemmodule' => 'lti','iteminstance' => $m->ltiid,'itemnumber' => 0]);
        if (!$item || abs($item->grademax - $max) > 0.00001 || $item->is_locked()) {
            throw new \RuntimeException('Gradebook item is locked or its scale differs');
        }
        $existing = \grade_grade::fetch(['itemid' => $item->id,'userid' => $userid]);
        if ($existing && ($existing->is_locked() || $existing->overridden)) {
            throw new \RuntimeException('Resolve local grade override before syncing');
        }
        $result = grade_update(
            'local_examsync',
            $m->courseid,
            'mod',
            'lti',
            $m->ltiid,
            0,
            ['userid' => $userid,'rawgrade' => $value,'dategraded' => (int)$d['dategraded']],
            ['hidden' => max(0, (int)$d['itemhidden'])]
        );
        if ($result !== GRADE_UPDATE_OK) {
            throw new \RuntimeException('Grade API rejected update');
        }
        $updated = \grade_grade::fetch(['itemid' => $item->id,'userid' => $userid]);
        if ($updated) {
            $updated->set_hidden(max(0, (int)$d['hidden']), false);
        }
        if (!$g) {
            $g = (object)['mapid' => $m->id,'userid' => $userid,'sequence' => $seq,'applied' => $seq];
            $DB->insert_record('local_examsync_grade', $g);
        } else {
            $g->applied = $seq;
            $g->sequence = $seq;
            $DB->update_record('local_examsync_grade', $g);
        }
        return ['accepted' => true];
    }
}
