<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Reads secrets the source stored encrypted with ITS app key (Laravel encrypter payloads) so they can be
 * re-encrypted with this install's key. The source key comes from config (env at cut-over); it is never logged,
 * never written anywhere and never echoed in the report. A value that is not an encrypter payload is plaintext
 * (older source versions kept gateway keys in plain option rows).
 *
 * Results: a string (the plaintext secret), or null when the value is encrypted but cannot be read (no source key,
 * wrong key, tampered payload); the caller reports the setting NAME and leaves the gateway off.
 */
final class SourceSecrets
{
    private ?Encrypter $encrypter = null;

    private bool $keyChecked = false;

    public function __construct(private readonly string $sourceKey, private readonly string $cipher) {}

    public function hasKey(): bool
    {
        return $this->encrypter() !== null;
    }

    /** True when $value looks like a Laravel encrypter payload (base64 JSON with iv, value and mac/tag). */
    public static function isEncrypted(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) < 40) {
            return false;
        }
        $json = base64_decode($value, true);
        if ($json === false) {
            return false;
        }
        $payload = json_decode($json, true);

        return is_array($payload) && isset($payload['iv'], $payload['value']) && (isset($payload['mac']) || isset($payload['tag']));
    }

    /** Plaintext of one source value (see class doc). */
    public function reveal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! self::isEncrypted($value)) {
            return is_scalar($value) ? (string) $value : null;
        }
        $encrypter = $this->encrypter();
        if ($encrypter === null) {
            return null;
        }
        try {
            $plain = $encrypter->decryptString((string) $value);
        } catch (DecryptException) {
            return null;
        }

        return self::unwrapSerializedString($plain);
    }

    /**
     * A JSON object the source stored encrypted as a whole (an `encrypted:array` column), or null when it cannot be
     * read.
     *
     * @return array<string, mixed>|null
     */
    public function revealArray(mixed $value): ?array
    {
        $plain = $this->reveal($value);
        if ($plain === null) {
            return null;
        }
        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Crypt::encrypt() (not encryptString) serializes the value first; only a serialized STRING is accepted here and
     * it is read without unserialize() (no object can ever be instantiated from source data).
     */
    private static function unwrapSerializedString(string $plain): string
    {
        if (preg_match('/^s:(\d+):"(.*)";$/sD', $plain, $m) === 1 && strlen($m[2]) === (int) $m[1]) {
            return $m[2];
        }

        return $plain;
    }

    private function encrypter(): ?Encrypter
    {
        if ($this->keyChecked) {
            return $this->encrypter;
        }
        $this->keyChecked = true;
        $key = trim($this->sourceKey);
        if ($key === '') {
            return null;
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? '' : $decoded;
        }
        $cipher = $this->cipher !== '' ? $this->cipher : 'AES-256-CBC';
        if ($key === '' || ! Encrypter::supported($key, $cipher)) {
            return null;
        }

        return $this->encrypter = new Encrypter($key, $cipher);
    }
}
