<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace quizaccess_examsync\privacy;

defined('MOODLE_INTERNAL') || die();
final class provider implements \core_privacy\local\metadata\null_provider
{
    public static function get_reason(): string
    {
        return 'privacy:metadata';
    }
}
