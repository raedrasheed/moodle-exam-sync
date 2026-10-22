<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class runner
{
    public static function run(string $kind, int $mapid, int $revision, array $extra): void
    {
        global $DB;
        $map = $DB->get_record('local_examsync_map', ['id' => $mapid], '*', MUST_EXIST);
        $lock = state::lock('resource:'.$map->uuid);
        try {
            $map = $DB->get_record('local_examsync_map', ['id' => $mapid], '*', MUST_EXIST);
            if (in_array($map->state, ['archived','privacyblocked'], true)) {
                return;
            }
            switch ($kind) {
                case 'publish':
                    if (state::role() !== 'source' || $revision !== (int)$map->revision || $map->applied >= $revision) {
                        return;
                    }
                    $shortlock = state::lock('map:'.$mapid);
                    try {
                        $map = $DB->get_record('local_examsync_map', ['id' => $mapid], '*', MUST_EXIST);
                        if ($revision !== (int)$map->revision || $map->frozen) {
                            return;
                        }
                        $op = $DB->get_record('local_examsync_op', ['id' => (int)$extra['opid'],'mapid' => $mapid], '*', MUST_EXIST);
                        source::publish($map, json_decode($op->payload, true, 512, JSON_THROW_ON_ERROR));
                        $DB->set_field('local_examsync_op', 'state', 'done', ['id' => $op->id]);
                        package::cleanup($map->uuid, $revision);
                        // Enrolment changes during backup may not have had a ready target yet.
                    } finally {
                        $shortlock->release();
                    }
                    observer::queue_membership($mapid);
                    return;
                case 'sync':
                    if (state::role() !== 'source' || $revision !== (int)$map->revision || $map->applied >= $revision) {
                        return;
                    }
                    $ctx = \context_module::instance($map->cmid);
                    if (!has_capability('local/examsync:manage', $ctx, $map->actorid)) {
                        throw new \RuntimeException('Publishing teacher no longer has permission');
                    }
                    if ($map->frozen) {
                        throw new \RuntimeException('Content cannot change after real attempts have started');
                    }
                    $dir = package::directory($map->uuid, $revision);
                    $manifest = $dir.'/manifest.json';
                    if (is_file($manifest)) {
                        $bundle = ['path' => $dir.'/exam.mbz','manifest' => json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR)];
                    } else {
                        $bundle = package::export($map);
                        file_put_contents($manifest, json_encode($bundle['manifest'], JSON_THROW_ON_ERROR));
                    }
                    // Edits may arrive during backup. Never publish an obsolete snapshot.
                    if ((int)$DB->get_field('local_examsync_map', 'revision', ['id' => $mapid]) !== $revision) {
                        return;
                    }
                    $reply = client::call('prepare', $bundle['manifest']);
                    if (empty($reply['done'])) {
                        $in = fopen($bundle['path'], 'rb');
                        if (!$in) {
                            throw new \RuntimeException('Cannot open package');
                        }
                        try {
                            $index = 0;
                            while (!feof($in)) {
                                $raw = fread($in, protocol::CHUNK);
                                if ($raw === '') {
                                    break;
                                }
                                client::call('chunk', ['uuid' => $map->uuid,'revision' => $revision,'index' => $index++,'content' => base64_encode($raw)]);
                            }
                        } finally {
                            fclose($in);
                        }
                        client::call('commit', ['uuid' => $map->uuid,'revision' => $revision]);
                    }
                    $DB->set_field('local_examsync_map', 'state', 'waiting', ['id' => $mapid]);
                    return;
                case 'restore':
                    if (state::role() !== 'target') {
                        return;
                    }
                    if ($revision < (int)$map->revision) {
                        return;
                    }
                    $op = $DB->get_record('local_examsync_op', ['id' => (int)$extra['opid'],'mapid' => $mapid], '*', MUST_EXIST);
                    $result = target::restore($map, $op);
                    state::enqueue('ack', $mapid, $revision, ['opid' => $op->id]);
                    return;
                case 'ack':
                    $op = $DB->get_record('local_examsync_op', ['id' => (int)$extra['opid'],'mapid' => $mapid], '*', MUST_EXIST);
                    if ($op->state !== 'done') {
                        throw new \RuntimeException('Restore is not complete');
                    }
                    client::call('ready', json_decode($op->result, true, 512, JSON_THROW_ON_ERROR));
                    package::cleanup($map->uuid, $revision);
                    return;
                case 'runtime':
                    if (state::role() !== 'source' || !$map->applied || $revision < (int)$map->runtimever) {
                        return;
                    }
                    client::call('runtime', ['uuid' => $map->uuid,'sequence' => $revision,'snapshot' => membership::snapshot($map)]);
                    return;
                case 'runtimeapply':
                    $op = $DB->get_record('local_examsync_op', ['id' => (int)$extra['opid'],'mapid' => $mapid], '*', MUST_EXIST);
                    if ($op->state === 'done' || $revision <= (int)$map->runtimever) {
                        $DB->update_record('local_examsync_op', (object)['id' => $op->id, 'payload' => null, 'state' => 'done']);
                        return;
                    }
                    if ($map->state !== 'ready') {
                        throw new \RuntimeException('Exam is not ready for enrolment updates');
                    }
                    membership::apply($map, json_decode($op->payload, true, 512, JSON_THROW_ON_ERROR));
                    $DB->set_field('local_examsync_map', 'runtimever', $revision, ['id' => $mapid]);
                    $DB->update_record('local_examsync_op', (object)['id' => $op->id, 'payload' => null, 'state' => 'done']);
                    return;
                case 'grade':grades::send($map, (int)$extra['userid']);
                    return;
                case 'frozen':client::call('frozen', ['uuid' => $map->uuid]);
                    return;
            }
            throw new \RuntimeException('Unknown task kind');
        } catch (\Throwable $e) {
            // Grade delivery errors must not close an otherwise usable exam.
            if (in_array($kind, ['sync','restore','publish'], true) &&
               (int)$DB->get_field('local_examsync_map', 'revision', ['id' => $mapid]) === $revision) {
                state::fail($mapid, $e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
