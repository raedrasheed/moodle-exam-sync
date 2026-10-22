<?php

// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
$capabilities = [
 'local/examsync:manage' => ['captype' => 'write','contextlevel' => CONTEXT_MODULE,
   'archetypes' => ['editingteacher' => CAP_ALLOW,'manager' => CAP_ALLOW]],
 'local/examsync:view' => ['captype' => 'read','contextlevel' => CONTEXT_COURSE,
   'archetypes' => ['teacher' => CAP_ALLOW,'editingteacher' => CAP_ALLOW,'manager' => CAP_ALLOW]],
];
