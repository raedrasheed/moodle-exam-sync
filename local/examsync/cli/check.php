<?php

// SPDX-License-Identifier: GPL-3.0-or-later
define('CLI_SCRIPT', true);
require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');
[$options,$unknown] = cli_get_params(['peer' => false,'help' => false], ['h' => 'help']);
if ($unknown) {
    cli_error('Unknown option');
}
if ($options['help']) {
    echo "Check ExamSync configuration. --peer also sends a signed health request. No secrets are printed.\n";
    exit(0);
}
$issues = \local_examsync\preflight::checks();
foreach ($issues as $issue) {
    echo 'FAIL: '.$issue.PHP_EOL;
}
if (!$issues) {
    echo "Local configuration: OK\n";
}
if ($options['peer']) {
    try {
        $peer = \local_examsync\client::call('health', []);
        if (($peer['role'] ?? '') === \local_examsync\state::role() || empty($peer['ready'])) {
            $issues[] = 'Peer configuration is not ready or has the same role.';
        }
        echo json_encode($peer, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
    } catch (Throwable $e) {
        $issues[] = $e->getMessage();
        echo 'FAIL: '.$e->getMessage().PHP_EOL;
    }
}
exit($issues ? 1 : 0);
