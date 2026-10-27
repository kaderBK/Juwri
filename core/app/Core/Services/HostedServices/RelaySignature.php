<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\HostedServices;

/**
 * The juwri Cloud partner relay's signature on a partner webhook it forwards to a self-hosted installation:
 * HMAC-SHA256 with that installation's service secret over "relay-inbound-v1\n<partner>\n<timestamp>\n<sha256(body)>",
 * fresh within {@see RequestSignature::WINDOW_SECONDS}. Both sides use this class.
 */
final class RelaySignature
{
    public const TIMESTAMP = 'X-Juwri-Relay-Timestamp';

    public const SIGNATURE = 'X-Juwri-Relay-Signature';

    /** Partner client methods the relay never carries (they take the caller's own partner credentials). */
    public const LOCAL_ONLY = ['authenticate'];

    public static function sign(string $secret, string $partner, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', implode("\n", ['relay-inbound-v1', $partner, (string) $timestamp, hash('sha256', $body)]), $secret);
    }

    public static function verify(string $secret, string $partner, string $timestamp, string $body, string $signature, int $now): bool
    {
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($now - (int) $timestamp) > RequestSignature::WINDOW_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($secret, $partner, (int) $timestamp, $body), $signature);
    }
}
