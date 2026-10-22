<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
/** Authenticated entry point shared by HTTP and integration tests. */
final class endpoint
{
    public static function handle(string $body, array $headers): array
    {
        global $DB;
        if (state::role() === 'disabled') {
            throw new \RuntimeException('Endpoint disabled');
        }
        $cfg = get_config('local_examsync');
        protocol::verify($cfg->secret ?? '', $cfg->peerid ?? '', $headers, $body, time());
        $DB->insert_record('local_examsync_nonce', (object)['nonce' => $headers['nonce'],'timecreated' => time()]);
        $DB->delete_records_select('local_examsync_nonce', 'timecreated < ?', [time() - 600]);
        $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (($request['version'] ?? 0) !== protocol::VERSION || !is_array($request['data'] ?? null)) {
            throw new \RuntimeException('Unsupported protocol');
        }
        return receiver::dispatch($request['action'] ?? '', $request['data']);
    }
}
