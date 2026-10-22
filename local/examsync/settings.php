<?php

// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_examsync', get_string('pluginname', 'local_examsync'));
    $ADMIN->add('localplugins', $settings);
    $settings->add(new admin_setting_configselect(
        'local_examsync/role',
        get_string('role', 'local_examsync'),
        '',
        'disabled',
        ['disabled' => 'Disabled','source' => 'Source Moodle','target' => 'Exam Moodle']
    ));
    foreach (['siteid','peerid','peerurl','issuer'] as $name) {
        $settings->add(new admin_setting_configtext('local_examsync/'.$name, get_string($name, 'local_examsync'), '', '', PARAM_RAW_TRIMMED));
    }
    $settings->add(new admin_setting_configpasswordunmask('local_examsync/secret', get_string('secret', 'local_examsync'), get_string('secret_help', 'local_examsync'), ''));
    foreach (['ltitypeid' => 0,'categoryid' => 1,'instructorrole' => 4,'learnerrole' => 5,'debounce' => 30,'maxpackage' => 134217728] as $name => $default) {
        $settings->add(new admin_setting_configtext('local_examsync/'.$name, get_string($name, 'local_examsync'), '', $default, PARAM_INT));
    }
    $settings->add(new admin_setting_configcheckbox('local_examsync/labhttp', get_string('labhttp', 'local_examsync'), get_string('labhttp_help', 'local_examsync'), 0));
}
