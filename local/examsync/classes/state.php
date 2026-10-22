<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class state
{
    public static bool $internal = false;
    public static function role(): string
    {
        return get_config('local_examsync', 'role') ?: 'disabled';
    }
    public static function lock(string $key): \core\lock\lock
    {
        $lock = \core\lock\lock_config::get_lock_factory('local_examsync')->get_lock($key, 30);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'local_examsync');
        }
        return $lock;
    }
    public static function map(int $quizid): ?\stdClass
    {
        global $DB;
        return $DB->get_record('local_examsync_map', ['quizid' => $quizid]) ?: null;
    }
    public static function enqueue(string $kind, int $mapid, int $revision = 0, array $extra = [], int $delay = 0): void
    {
        $task = new \local_examsync\task\dispatch();
        $task->set_custom_data(['kind' => $kind,'mapid' => $mapid,'revision' => $revision,'extra' => $extra]);
        $task->set_component('local_examsync');
        global $DB;
        $actor = (int)$DB->get_field('local_examsync_map', 'actorid', ['id' => $mapid]);
        if ($actor) {
            $task->set_userid($actor);
        }
        $task->set_next_run_time(time() + $delay);
        \core\task\manager::queue_adhoc_task($task, true);
    }
    public static function dirty(int $mapid): void
    {
        global $DB;
        if (self::$internal || self::role() !== 'source') {
            return;
        }
        $lock = self::lock('map:'.$mapid);
        try {
            $m = $DB->get_record('local_examsync_map', ['id' => $mapid], '*', MUST_EXIST);
            if (in_array($m->state, ['archived','privacyblocked'], true)) {
                return;
            }
            $m->revision++;
            $m->state = 'pending';
            $m->error = null;
            $m->timemodified = time();
            $DB->update_record('local_examsync_map', $m);
            // Each revision is independently queued. Superseded tasks exit before export.
            self::enqueue('sync', $m->id, $m->revision, [], (int)(get_config('local_examsync', 'debounce') ?: 30));
        } finally {
            $lock->release();
        }
    }
    public static function fail(int $id, \Throwable $e): void
    {
        global $DB;
        $DB->update_record('local_examsync_map', (object)['id' => $id,'state' => 'error',
         'error' => mb_substr($e->getMessage(), 0, 1000),'timemodified' => time()]);
    }
    public static function operation(string $key, string $kind, int $mapid, int $rev, array $payload = []): \stdClass
    {
        global $DB;
        $op = $DB->get_record('local_examsync_op', ['opkey' => $key]);
        if ($op) {
            return $op;
        }
        $op = (object)['opkey' => $key,'kind' => $kind,'mapid' => $mapid,'revision' => $rev,'state' => 'pending',
         'payload' => json_encode($payload, JSON_THROW_ON_ERROR),'timecreated' => time(),'timemodified' => time()];
        $op->id = $DB->insert_record('local_examsync_op', $op);
        return $op;
    }
}
