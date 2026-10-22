<?php

// SPDX-License-Identifier: GPL-3.0-or-later
define('CLI_SCRIPT', true);
require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');
[$options,$unknown] = cli_get_params(['file' => null,'help' => false], ['h' => 'help']);
if ($options['help'] || !$options['file']) {
    echo "Usage: php local/examsync/cli/configure.php --file=/secure/path/site.json\nThe file contains local_examsync settings; keep it outside the repository. Existing pair identity cannot be changed when mappings exist.\n";
    exit($options['help'] ? 0 : 1);
}
if ($unknown) {
    cli_error('Unknown option');
}
$input = json_decode(file_get_contents($options['file']), true, 512, JSON_THROW_ON_ERROR);
$allowed = ['role','siteid','peerid','peerurl','issuer','secret','ltitypeid','categoryid','instructorrole','learnerrole','debounce','maxpackage','labhttp'];
foreach ($input as $key => $value) {
    if (!in_array($key, $allowed, true) || !is_scalar($value)) {
        cli_error('Invalid setting: '.$key);
    }
}
if ($DB->record_exists('local_examsync_map', [])) {
    foreach (['role','siteid','peerid','issuer'] as $key) {
        if (isset($input[$key]) && (string)$input[$key] !== get_config('local_examsync', $key)) {
            cli_error('Cannot change pair identity while exam mappings exist: '.$key);
        }
    }
}
if (isset($input['secret']) && (strlen($input['secret']) < 32 || str_starts_with($input['secret'], 'REPLACE_'))) {
    cli_error('Replace the placeholder with a random secret of at least 32 characters.');
}
if (isset($input['peerurl'])) {
    \local_examsync\protocol::baseurl($input['peerurl'], !empty($input['labhttp']));
}
foreach ($input as $key => $value) {
    set_config($key, $value, 'local_examsync');
}
echo "Settings saved. Run cli/check.php --peer on both sites.\n";
