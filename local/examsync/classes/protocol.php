<?php

// SPDX-License-Identifier: GPL-3.0-or-later

namespace local_examsync;

/** Pure protocol validation, also executable without a Moodle installation. */
final class protocol
{
    public const VERSION = 1;
    public const CHUNK = 1048576;
    public const MAX_BODY = 2097152;
    public static function sign(string $secret, string $sender, int $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $sender."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), $secret);
    }
    public static function verify(string $secret, string $expected, array $headers, string $body, int $now): void
    {
        if (strlen($secret) < 32 || str_starts_with($secret, 'REPLACE_') || strlen($body) > self::MAX_BODY) {
            throw new \RuntimeException('Invalid secret or request size');
        }
        if (($headers['sender'] ?? '') !== $expected || !preg_match('/^[a-f0-9]{32}$/D', $headers['nonce'] ?? '')) {
            throw new \RuntimeException('Untrusted sender or invalid nonce');
        }
        $ts = $headers['timestamp'] ?? '';
        if (!ctype_digit((string)$ts) || abs($now - (int)$ts) > 120) {
            throw new \RuntimeException('Expired request');
        }
        $signature = self::sign($secret, $expected, (int)$ts, $headers['nonce'], $body);
        if (!hash_equals($signature, $headers['signature'] ?? '')) {
            throw new \RuntimeException('Invalid signature');
        }
    }
    public static function uuid(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value)) {
            throw new \InvalidArgumentException('Invalid resource UUID');
        }
        return $value;
    }
    public static function revision(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('Invalid revision');
        }
        return $value;
    }
    public static function may_replace(int $applied, int $incoming, bool $hasattempts, bool $frozen): bool
    {
        return $incoming > $applied && !$hasattempts && !$frozen;
    }
    public static function baseurl(string $url, bool $lab = false): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid peer URL');
        }
        if (($parts['scheme'] ?? '') !== 'https' && !($lab && ($parts['scheme'] ?? '') === 'http')) {
            throw new \InvalidArgumentException('HTTPS is required outside the explicit local lab mode');
        }
        return rtrim($url, '/');
    }
}
