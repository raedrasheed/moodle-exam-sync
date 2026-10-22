<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

defined('MOODLE_INTERNAL') || die();
final class client
{
    public static function call(string $action, array $data): array
    {
        global $CFG;
        require_once($CFG->libdir.'/filelib.php');
        $cfg = get_config('local_examsync');
        $url = protocol::baseurl($cfg->peerurl ?? '', !empty($cfg->labhttp));
        $body = json_encode(['version' => protocol::VERSION,'action' => $action,'data' => $data], JSON_THROW_ON_ERROR);
        if (strlen($body) > protocol::MAX_BODY) {
            throw new \RuntimeException('Message exceeds protocol limit');
        }
        $nonce = bin2hex(random_bytes(16));
        $time = time();
        $sender = $cfg->siteid ?? '';
        $sig = protocol::sign($cfg->secret ?? '', $sender, $time, $nonce, $body);
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json','X-ExamSync-Sender: '.$sender,
         'X-ExamSync-Time: '.$time,'X-ExamSync-Nonce: '.$nonce,'X-ExamSync-Signature: '.$sig]);
        $response = $curl->post($url.'/local/examsync/api.php', $body, [
         'CURLOPT_CONNECTTIMEOUT' => 10,'CURLOPT_TIMEOUT' => 60,'CURLOPT_FOLLOWLOCATION' => false,
         'CURLOPT_SSL_VERIFYPEER' => true,'CURLOPT_SSL_VERIFYHOST' => 2]);
        $info = $curl->get_info();
        if ($curl->get_errno() || ($info['http_code'] ?? 0) !== 200) {
            throw new \RuntimeException('Peer request unsuccessful: '.$action);
        }
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (empty($result['ok'])) {
            throw new \RuntimeException('Peer rejected '.$action.': '.($result['error'] ?? 'Unknown error'));
        }
        return $result['data'];
    }
}
