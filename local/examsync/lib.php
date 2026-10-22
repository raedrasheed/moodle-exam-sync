<?php

// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
function local_examsync_extend_settings_navigation($navigation, $context)
{
    global $PAGE;
    if ($context->contextlevel === CONTEXT_COURSE && has_capability('local/examsync:view', $context)) {
        if ($course = $navigation->find('courseadmin', navigation_node::TYPE_COURSE)) {
            $course->add(get_string('pluginname', 'local_examsync'), new moodle_url('/local/examsync/index.php', ['courseid' => $context->instanceid]), navigation_node::TYPE_SETTING);
        }
    }
}
