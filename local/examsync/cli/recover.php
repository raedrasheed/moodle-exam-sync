<?php

// SPDX-License-Identifier: GPL-3.0-or-later
define('CLI_SCRIPT', true);
require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');
[$options,$unknown] = cli_get_params(['uuid' => null,'release-unused-freeze' => false,'help' => false], ['h' => 'help']);
if ($options['help'] || !$options['uuid'] || !$options['release-unused-freeze']) {
    echo "Usage: php local/examsync/cli/recover.php --uuid=UUID --release-unused-freeze\nRun on the source site only. Pauses new admissions and asks the target to verify zero real attempts before releasing an abandoned admission freeze and queuing a new revision. Existing real attempts always prevent recovery; clone the quiz instead.\n";
    exit($options['help'] ? 0 : 1);
}
if ($unknown) {
    cli_error('Unknown option');
}
if (\local_examsync\state::role() !== 'source') {
    cli_error('Run this on the source site.');
}
$uuid = \local_examsync\protocol::uuid($options['uuid']);
$m = $DB->get_record('local_examsync_map', ['uuid' => $uuid,'side' => 'source'], '*', MUST_EXIST);
if (in_array($m->state, ['archived','privacyblocked'], true)) {
    cli_error('This resource cannot be resumed by this command.');
}
$resourcelock = \local_examsync\state::lock('resource:'.$uuid);
$lock = \local_examsync\state::lock('map:'.$m->id);
try {
    $DB->set_field('local_examsync_map', 'state', 'paused', ['id' => $m->id]);
} finally {
    $lock->release();
}
try {
    \local_examsync\client::call('resetfreeze', ['uuid' => $uuid]);
    $DB->set_field('local_examsync_map', 'frozen', 0, ['id' => $m->id]);
    $DB->set_field('local_examsync_map', 'actorid', get_admin()->id, ['id' => $m->id]);
    \local_examsync\state::dirty($m->id);
    $resourcelock->release();
    echo "Unused admission freeze released; a fresh revision is queued.\n";
} catch (Throwable $e) {
    $resourcelock->release();
    cli_error('Recovery stopped; admissions remain paused. '.$e->getMessage());
}
