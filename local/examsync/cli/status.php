<?php

// SPDX-License-Identifier: GPL-3.0-or-later
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
[$options, $unknown] = cli_get_params(['courseid' => 0, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Show resource identifiers and synchronization state. Optional --courseid=ID. No secrets or student profiles are printed.\n";
    exit(0);
}
if ($unknown) {
    cli_error('Unknown option');
}
$conditions = $options['courseid'] ? ['courseid' => (int)$options['courseid']] : [];
foreach ($DB->get_records('local_examsync_map', $conditions, 'id') as $m) {
    echo json_encode([
        'uuid' => $m->uuid, 'courseid' => (int)$m->courseid, 'quizid' => (int)$m->quizid,
        'state' => $m->state, 'revision' => (int)$m->revision, 'applied' => (int)$m->applied,
        'frozen' => (bool)$m->frozen, 'lti_cmid' => (int)$m->lticmid,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
