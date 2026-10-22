<?php

// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'local_examsync';
$plugin->version = 2026102100;
$plugin->requires = 2025100600;
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.1.0-dev';
$plugin->dependencies = ['mod_quiz' => 2025100600, 'mod_lti' => 2025100600, 'enrol_lti' => 2025100600];
