<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync\task;

defined('MOODLE_INTERNAL') || die();
class dispatch extends \core\task\adhoc_task
{
    public function execute()
    {
        $d = $this->get_custom_data();
        \local_examsync\runner::run($d->kind, (int)$d->mapid, (int)$d->revision, (array)$d->extra);
    }
}
