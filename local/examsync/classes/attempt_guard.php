<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class attempt_guard
{
    private static ?\core\lock\lock $lock = null;
    public static function begin(\stdClass $map, int $userid): void
    {
        global $DB;
        self::release();
        self::$lock = state::lock('resource:'.$map->uuid);
        register_shutdown_function([self::class,'release']);
        try {
            $m = $DB->get_record('local_examsync_map', ['id' => $map->id], '*', MUST_EXIST);
            if ($m->state !== 'ready' || $m->applied < 1) {
                throw new \RuntimeException('Exam is not ready');
            }
            $user = $DB->get_record('local_examsync_user', ['userid' => $userid], '*', MUST_EXIST);
            $reply = client::call('authorize', ['uuid' => $m->uuid,'revision' => (int)$m->applied,'userid' => (int)$user->sourceid]);
            if (empty($reply['allowed'])) {
                throw new \RuntimeException('Source denied eligibility or its revision changed');
            }
            // Conservatively freeze on admission, before core creates the attempt. This closes the publish/start race.
            $DB->set_field('local_examsync_map', 'frozen', 1, ['id' => $m->id]);
        } catch (\Throwable $e) {
            self::release();
            throw $e;
        }
    }
    public static function release(): void
    {
        if (self::$lock) {
            self::$lock->release();
            self::$lock = null;
        }
    }
}
