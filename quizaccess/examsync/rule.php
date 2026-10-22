<?php

// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
use local_examsync\state;

class quizaccess_examsync extends \mod_quiz\local\access_rule_base
{
    public static function make(\mod_quiz\quiz_settings $quizobj, $timenow, $canignoretimelimits)
    {
        $map = state::map((int)$quizobj->get_quizid());
        $cm = $quizobj->get_cm();
        if (!$map && !(state::role() === 'target' && str_starts_with($cm->idnumber ?? '', 'examsync:'))) {
            return null;
        }
        return new self($quizobj, $timenow);
    }
    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodleQuickForm $mform)
    {
        if (state::role() !== 'source') {
            return;
        }
        $mform->addElement('header', 'examsyncheader', get_string('pluginname', 'quizaccess_examsync'));
        $mform->addElement('advcheckbox', 'examsync_enabled', get_string('enabled', 'quizaccess_examsync'));
        $mform->addHelpButton('examsync_enabled', 'enabled', 'quizaccess_examsync');
    }
    public static function get_settings_sql($quizid): array
    {
        return ['CASE WHEN examsyncmap.id IS NULL THEN 0 ELSE 1 END AS examsync_enabled',
         "LEFT JOIN {local_examsync_map} examsyncmap ON examsyncmap.quizid=quiz.id AND examsyncmap.side='source'",[]];
    }
    public static function save_settings($quiz)
    {
        \local_examsync\source::enable($quiz);
    }
    public static function validate_settings_form_fields(array $errors, array $data, $files, mod_quiz_mod_form $quizform)
    {
        global $DB;
        if (state::role() === 'source') {
            $id = (int)($data['instance'] ?? 0);
            $map = $id ? state::map($id) : null;
            if ($map && empty($data['examsync_enabled'])) {
                $errors['examsync_enabled'] = get_string('cannotdisable', 'local_examsync');
            }
            if (!$map && !empty($data['examsync_enabled']) && $id && $DB->record_exists('quiz_attempts', ['quiz' => $id,'preview' => 0])) {
                $errors['examsync_enabled'] = get_string('existingattempts', 'local_examsync');
            }
        }
        return $errors;
    }
    public function description()
    {
        $m = state::map((int)$this->quiz->id);
        if ($m && $m->side === 'source') {
            return get_string('remoteonly', 'quizaccess_examsync').
              ($m->lticmid ? ' '.html_writer::link(new moodle_url('/mod/lti/view.php', ['id' => $m->lticmid]), get_string('openexam', 'local_examsync')) : '');
        }
        return false;
    }
    public function prevent_new_attempt($numprevattempts, $lastattempt)
    {
        $m = state::map((int)$this->quiz->id);
        if (!$m) {
            return get_string('notready', 'local_examsync');
        }
        if ($m->side === 'source') {
            return $this->quizobj->is_preview_user() ? false : get_string('remoteonly', 'quizaccess_examsync');
        }
        if ($m->state !== 'ready') {
            return get_string('notready', 'local_examsync');
        }
        return false;
    }
    public function is_preflight_check_required($attemptid)
    {
        $m = state::map((int)$this->quiz->id);
        return !$attemptid && !$this->quizobj->is_preview_user() && $m && $m->side === 'target';
    }
    public function add_preflight_check_form_fields(\mod_quiz\form\preflight_check_form $quizform, MoodleQuickForm $mform, $attemptid)
    {
        $mform->addElement('advcheckbox', 'examsync_confirm', get_string('confirm', 'quizaccess_examsync'));
    }
    public function validate_preflight_check($data, $files, $errors, $attemptid)
    {
        global $USER;
        if ($attemptid) {
            return $errors;
        }
        if (empty($data['examsync_confirm'])) {
            $errors['examsync_confirm'] = get_string('required');
            return $errors;
        }
        if ($errors) {
            return $errors;
        }
        try {
            \local_examsync\attempt_guard::begin(state::map((int)$this->quiz->id), (int)$USER->id);
        } catch (Throwable $e) {
            $errors['examsync_confirm'] = get_string('admissiondenied', 'local_examsync');
        }
        return $errors;
    }
    public function prevent_access()
    {
        $m = state::map((int)$this->quiz->id);
        // Existing attempts keep working during a content-sync problem; only admission is gated.
        if ($m && $m->side === 'source' && !has_capability('mod/quiz:preview', $this->quizobj->get_context())) {
            return $this->description();
        }
        if (!$m) {
            return get_string('notready', 'local_examsync');
        }
        return false;
    }
}
