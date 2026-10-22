<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class receiver
{
    public static function dispatch(string $action, array $data): array
    {
        global $DB;
        $role = state::role();
        if ($action === 'health') {
            return preflight::health();
        }
        $allowed = $role === 'source' ? ['ready','authorize','grade','frozen','status'] : ['prepare','chunk','commit','runtime','status','resetfreeze'];
        if (!in_array($action, $allowed, true)) {
            throw new \RuntimeException('Action is not allowed on this side');
        }
        $uuid = protocol::uuid($data['uuid'] ?? '');
        // Admission uses the source's short map lock; never wait on an outbound network task.
        if ($action === 'authorize') {
            return source::authorize($data);
        }
        $lock = state::lock('resource:'.$uuid);
        try {
            if ($action === 'prepare') {
                return self::prepare($data);
            }
            $m = $DB->get_record('local_examsync_map', ['uuid' => $uuid], '*', MUST_EXIST);
            if ($m->state === 'privacyblocked' && $action !== 'status') {
                throw new \RuntimeException('Resource paused after privacy erasure');
            }
            switch ($action) {
                case 'status':return ['state' => $m->state,'revision' => (int)$m->applied,'frozen' => (bool)$m->frozen,
                 'attempted' => $m->quizid && $DB->record_exists('quiz_attempts', ['quiz' => $m->quizid,'preview' => 0])];
                case 'resetfreeze':
                    if ($m->quizid && $DB->record_exists('quiz_attempts', ['quiz' => $m->quizid,'preview' => 0])) {
                        throw new \RuntimeException('Real attempts exist; clone the source quiz instead');
                    }
                    $DB->set_field('local_examsync_map', 'frozen', 0, ['id' => $m->id]);
                    return ['accepted' => true];
                case 'authorize':return source::authorize($data);
                case 'frozen':$DB->set_field('local_examsync_map', 'frozen', 1, ['id' => $m->id]);
                    return ['accepted' => true];
                case 'ready':
                    if ((int)$data['revision'] === (int)$m->revision && $m->applied < $m->revision) {
                        $op = state::operation('publish:'.$uuid.':'.$data['revision'], 'publish', $m->id, (int)$data['revision'], $data);
                        state::enqueue('publish', $m->id, (int)$data['revision'], ['opid' => $op->id]);
                    }
                    return ['accepted' => true];
                case 'grade':return grades::receive($m, $data);
                case 'runtime':
                    $seq = protocol::revision($data['sequence'] ?? null);
                    $op = state::operation('runtime:'.$uuid.':'.$seq, 'runtime', $m->id, $seq, $data['snapshot']);
                    state::enqueue('runtimeapply', $m->id, $seq, ['opid' => $op->id]);
                    return ['accepted' => true];
                case 'chunk':return self::chunk($m, $data);
                case 'commit':return self::commit($m, $data);
            }
            throw new \RuntimeException('Unknown action');
        } finally {
            $lock->release();
        }
    }
    private static function prepare(array $meta): array
    {
        global $DB;
        $rev = protocol::revision($meta['revision'] ?? null);
        $size = $meta['size'] ?? 0;
        if (!is_int($size) || $size < 1 || $size > (int)(get_config('local_examsync', 'maxpackage') ?: 134217728) ||
           !preg_match('/^[a-f0-9]{64}$/D', $meta['sha256'] ?? '')) {
            throw new \RuntimeException('Invalid package manifest');
        }
        if (($meta['branch'] ?? '') !== '501') {
            throw new \RuntimeException('Unsupported Moodle branch');
        }
        $map = $DB->get_record('local_examsync_map', ['uuid' => $meta['uuid']]);
        if ($map && $map->state === 'privacyblocked') {
            throw new \RuntimeException('Resource paused after privacy erasure');
        }
        if (!$map) {
            $map = (object)['uuid' => $meta['uuid'],'side' => 'target','courseid' => 0,'cmid' => 0,'quizid' => 0,'revision' => 0,'applied' => 0,
             'state' => 'pending','frozen' => 0,'actorid' => get_admin()->id,'origvisible' => 1,'timecreated' => time(),'timemodified' => time()];
            $map->id = $DB->insert_record('local_examsync_map', $map);
        }
        $key = 'package:'.$meta['uuid'].':'.$rev;
        $op = $DB->get_record('local_examsync_op', ['opkey' => $key]);
        if ($op) {
            $old = json_decode($op->payload, true, 512, JSON_THROW_ON_ERROR);
            if (!hash_equals($old['sha256'], $meta['sha256'])) {
                throw new \RuntimeException('Revision content conflict');
            }
            if ($op->state === 'done') {
                state::enqueue('ack', $map->id, $rev, ['opid' => $op->id]);
            }
            return ['accepted' => true,'done' => $op->state === 'done'];
        }
        $attempted = $map->quizid && $DB->record_exists('quiz_attempts', ['quiz' => $map->quizid,'preview' => 0]);
        if ($rev < (int)$map->revision || !protocol::may_replace((int)$map->applied, $rev, (bool)$attempted, (bool)$map->frozen)) {
            throw new \RuntimeException('Exam content is frozen or revision is stale');
        }
        state::operation($key, 'package', $map->id, $rev, $meta);
        $map->state = 'receiving';
        $map->revision = $rev;
        $map->timemodified = time();
        $DB->update_record('local_examsync_map', $map);
        package::directory($map->uuid, $rev);
        return ['accepted' => true,'done' => false];
    }
    private static function chunk(\stdClass $m, array $data): array
    {
        global $DB;
        $rev = protocol::revision($data['revision'] ?? null);
        $op = $DB->get_record('local_examsync_op', ['opkey' => 'package:'.$m->uuid.':'.$rev], '*', MUST_EXIST);
        if ($op->state === 'done') {
            return ['accepted' => true];
        }
        $meta = json_decode($op->payload, true, 512, JSON_THROW_ON_ERROR);
        $index = $data['index'] ?? -1;
        $count = (int)ceil($meta['size'] / protocol::CHUNK);
        if (!is_int($index) || $index < 0 || $index >= $count) {
            throw new \RuntimeException('Invalid chunk index');
        }
        $raw = base64_decode($data['content'] ?? '', true);
        $expected = min(protocol::CHUNK, $meta['size'] - $index * protocol::CHUNK);
        if ($raw === false || strlen($raw) !== $expected) {
            throw new \RuntimeException('Invalid chunk size');
        }
        $file = package::directory($m->uuid, $rev).'/chunk-'.$index;
        if (file_put_contents($file, $raw, LOCK_EX) !== $expected) {
            throw new \RuntimeException('Chunk write failed');
        }
        return ['accepted' => true];
    }
    private static function commit(\stdClass $m, array $data): array
    {
        global $DB;
        $rev = protocol::revision($data['revision'] ?? null);
        $op = $DB->get_record('local_examsync_op', ['opkey' => 'package:'.$m->uuid.':'.$rev], '*', MUST_EXIST);
        if ($op->state === 'done') {
            state::enqueue('ack', $m->id, $rev, ['opid' => $op->id]);
            return ['accepted' => true];
        }
        $meta = json_decode($op->payload, true, 512, JSON_THROW_ON_ERROR);
        $dir = package::directory($m->uuid, $rev);
        $out = fopen($dir.'/exam.partial', 'wb');
        if (!$out) {
            throw new \RuntimeException('Cannot assemble package');
        }
        try {
            for ($i = 0;$i < (int)ceil($meta['size'] / protocol::CHUNK);$i++) {
                $file = $dir.'/chunk-'.$i;
                if (!is_file($file)) {
                    throw new \RuntimeException('Missing chunk');
                }
                $in = fopen($file, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        if (filesize($dir.'/exam.partial') !== $meta['size'] || !hash_equals($meta['sha256'], hash_file('sha256', $dir.'/exam.partial'))) {
            throw new \RuntimeException('Incomplete package');
        }
        rename($dir.'/exam.partial', $dir.'/exam.mbz');
        state::enqueue('restore', $m->id, $rev, ['opid' => $op->id]);
        return ['accepted' => true];
    }
}
