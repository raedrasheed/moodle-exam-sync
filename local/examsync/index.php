<?php

// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/examsync:view', $context);
$PAGE->set_context($context);
$PAGE->set_url('/local/examsync/index.php', ['courseid' => $courseid]);
$PAGE->set_title(get_string('pluginname', 'local_examsync'));
$PAGE->set_heading($course->fullname);
if ($retry = optional_param('retry', 0, PARAM_INT)) {
    require_sesskey();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('invalidrequest');
    }
    $m = $DB->get_record('local_examsync_map', ['id' => $retry,'courseid' => $courseid], '*', MUST_EXIST);
    require_capability('local/examsync:manage', context_module::instance($m->cmid));
    if ($m->side === 'source') {
        $DB->set_field('local_examsync_map', 'actorid', $USER->id, ['id' => $m->id]);
        \local_examsync\state::enqueue('sync', $m->id, $m->revision);
    }
    redirect($PAGE->url);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_examsync'));
$table = new html_table();
$table->head = [get_string('modulename', 'quiz'),get_string('status', 'local_examsync'),get_string('revision', 'local_examsync'),get_string('applied', 'local_examsync'),get_string('frozen', 'local_examsync'),get_string('error', 'local_examsync'),get_string('action')];
foreach ($DB->get_records('local_examsync_map', ['courseid' => $courseid]) as $m) {
    $name = $DB->get_field('quiz', 'name', ['id' => $m->quizid]) ?: $m->uuid;
    $button = $m->side === 'source' && has_capability('local/examsync:manage', $context) ? $OUTPUT->single_button(new moodle_url($PAGE->url, ['retry' => $m->id]), get_string('retry', 'local_examsync'), 'post') : '';
    $link = $m->side === 'source' && $m->lticmid ? html_writer::link(new moodle_url('/mod/lti/view.php', ['id' => $m->lticmid]), get_string('openexam', 'local_examsync')) : '';
    $table->data[] = [s($name),get_string('state_'.$m->state, 'local_examsync'),$m->revision,$m->applied,get_string($m->frozen ? 'yes' : 'no'),s($m->error ?? ''),$button.$link];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
