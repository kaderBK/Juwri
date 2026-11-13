<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Bridge;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * P6-09 V-01: signed, short-lived, single-use voice-bridge token.
 *
 * The inbound-call webhook (provider signature already verified) issues it and
 * appends it to the bridge URL it hands the provider. The bridge refuses any
 * connection whose token is missing, forged, expired, already used, or bound to a
 * different provider / line / store. Format: `v1.<b64url(json claims)>.<b64url(hmac)>`,
 * HMAC-SHA256 under `services.telephony.bridge_secret`, or a key derived from APP_KEY.
 */
final class BridgeToken
{
    public const VERSION = 'v1';

    public const TTL_SECONDS = 120;

    private const NONCE_PREFIX = 'voice-bridge-nonce:';

    public static function issue(string $provider, PhoneLine $line, string $callId, ?int $ttl = null): string
    {
        $claims = [
            'p' => $provider,
            'l' => (int) $line->id,
            's' => (int) $line->store_id,
            'c' => $callId,
            'exp' => now()->getTimestamp() + ($ttl ?? self::TTL_SECONDS),
            'n' => Str::random(32),
        ];
        $body = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        return self::VERSION.'.'.$body.'.'.self::b64(self::mac(self::VERSION.'.'.$body));
    }

    /**
     * Verify signature, expiry and binding to (provider, line, line's current store).
     * Does NOT consume the token — see consume(). Returns the claims or null.
     *
     * @return array{p: string, l: int, s: int, c: string, exp: int, n: string}|null
     */
    public static function verify(?string $token, string $provider, PhoneLine $line): ?array
    {
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }
        $expected = self::b64(self::mac($parts[0].'.'.$parts[1]));
        if (! hash_equals($expected, $parts[2])) {
            return null;
        }
        $json = self::unb64($parts[1]);
        $claims = $json === null ? null : json_decode($json, true);
        if (! is_array($claims)) {
            return null;
        }
        foreach (['p' => 'is_string', 'l' => 'is_int', 's' => 'is_int', 'c' => 'is_string', 'exp' => 'is_int', 'n' => 'is_string'] as $k => $check) {
            if (! isset($claims[$k]) || ! $check($claims[$k])) {
                return null;
            }
        }
        if ($claims['exp'] < now()->getTimestamp()
            || ! hash_equals($claims['p'], $provider)
            || $claims['l'] !== (int) $line->id
            || $claims['s'] !== (int) $line->store_id
            || $claims['c'] === '') {
            return null;
        }

        /** @var array{p: string, l: int, s: int, c: string, exp: int, n: string} $claims */
        return $claims;
    }

    /**
     * Mark a verified token used. False if it was already used (replay).
     *
     * @param  array{p: string, l: int, s: int, c: string, exp: int, n: string}  $claims
     */
    public static function consume(array $claims): bool
    {
        $ttl = max(1, (int) $claims['exp'] - now()->getTimestamp() + 60);

        return Cache::add(self::NONCE_PREFIX.$claims['n'], 1, $ttl);
    }

    private static function mac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key(), true);
    }

    private static function key(): string
    {
        $secret = (string) config('services.telephony.bridge_secret', '');
        if ($secret !== '') {
            return $secret;
        }
        $appKey = (string) config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }
        if ($appKey === '') {
            throw new \RuntimeException('No key for voice bridge tokens (set APP_KEY or services.telephony.bridge_secret).');
        }

        // Domain-separated so the token key is never the raw APP_KEY.
        return hash_hmac('sha256', 'juwri.voice-bridge.v1', $appKey, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        $raw = base64_decode(strtr($s, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
