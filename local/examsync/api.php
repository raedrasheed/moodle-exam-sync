<?php

// SPDX-License-Identifier: GPL-3.0-or-later
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__.'/../../config.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || \local_examsync\state::role() === 'disabled') {
        throw new RuntimeException('Endpoint disabled');
    }
    $body = file_get_contents('php://input', false, null, 0, \local_examsync\protocol::MAX_BODY + 1);
    $headers = ['sender' => $_SERVER['HTTP_X_EXAMSYNC_SENDER'] ?? '','timestamp' => $_SERVER['HTTP_X_EXAMSYNC_TIME'] ?? '',
     'nonce' => $_SERVER['HTTP_X_EXAMSYNC_NONCE'] ?? '','signature' => $_SERVER['HTTP_X_EXAMSYNC_SIGNATURE'] ?? ''];
    $result = \local_examsync\endpoint::handle($body, $headers);
    echo json_encode(['ok' => true,'data' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    // Never include payloads, credentials, SQL, or stack traces in remote responses.
    echo json_encode(['ok' => false,'error' => 'Request rejected; inspect the receiving site task/status log.']);
}
