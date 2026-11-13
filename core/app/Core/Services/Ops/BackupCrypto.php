<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Ops;

use RuntimeException;

/**
 * P6-96: streaming encryption of platform DB dumps at rest (libsodium secretstream, XChaCha20-Poly1305).
 *
 * File format (version 1), everything after the header is authenticated:
 *   "JWBK"            4 bytes  magic
 *   0x01              1 byte   format version
 *   key id            8 bytes  first 8 bytes of BLAKE2b("juwri-backup-key-id" || key) — identifies, never reveals, the key
 *   stream header    24 bytes  crypto_secretstream_xchacha20poly1305 header
 *   frames                     repeated: 4-byte big-endian ciphertext length + ciphertext (≤ 64 KiB plaintext each)
 * The last frame carries TAG_FINAL; a missing final frame (truncation), trailing bytes, a modified frame or a
 * wrong key all fail. The 13 header bytes are bound to every frame as additional data, so a swapped key id fails too.
 *
 * Key: BACKUP_ENCRYPTION_KEY, 32 random bytes base64-encoded (optional "base64:" prefix). Generate with
 * `php artisan backup:generate-key`. BACKUP_ENCRYPTION_PREVIOUS_KEYS (comma-separated) keeps rotated keys
 * available for restoring older dumps. The key is never logged or printed; messages only carry the key id.
 * No key configured → dumps stay plain gzip (backward compatible); plain dumps always restore.
 */
final class BackupCrypto
{
    public const MAGIC = 'JWBK';

    public const VERSION = 1;

    private const HEADER_LEN = 4 + 1 + 8;

    private const CHUNK = 65536;

    /** J45 (P6-204): plain fix message stored on the failed run, sent in the alert and shown on Backup Health. */
    public const MISSING_KEY_IN_PRODUCTION = 'Backup stopped: BACKUP_ENCRYPTION_KEY is not set on this live site, so nothing was dumped or uploaded. Fix: run `php artisan backup:generate-key`, put the key in .env as BACKUP_ENCRYPTION_KEY, then run the backup again.';

    /**
     * J45 (P6-204): true in production when no backup key is configured — every platform backup must then stop
     * (no dump, no upload) and alert. A malformed key is not "missing": currentKey() throws and each caller already
     * rejects the run on that. Non-production keeps the old behaviour (plain gzip + a warning).
     */
    public static function missingInProduction(): bool
    {
        if (config('app.env') !== 'production') {
            return false;
        }
        try {
            return self::currentKey() === null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Current key (raw 32 bytes), or null when none is configured. Throws on a malformed key: never silently plain. */
    public static function currentKey(): ?string
    {
        return self::decodeKey((string) config('backup.encryption_key', ''), 'BACKUP_ENCRYPTION_KEY');
    }

    /** @return list<string> current + previous raw keys, malformed entries throw */
    public static function allKeys(): array
    {
        $keys = [];
        if (($k = self::currentKey()) !== null) {
            $keys[] = $k;
        }
        foreach (explode(',', (string) config('backup.encryption_previous_keys', '')) as $raw) {
            if (($k = self::decodeKey($raw, 'BACKUP_ENCRYPTION_PREVIOUS_KEYS')) !== null) {
                $keys[] = $k;
            }
        }

        return $keys;
    }

    public static function decodeKey(string $raw, string $name = 'key'): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, 'base64:')) {
            $raw = substr($raw, 7);
        }
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException($name.' is malformed: it must be base64 of exactly 32 bytes (php artisan backup:generate-key)');
        }

        return $key;
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** 16-hex key id. Safe to print/store. */
    public static function keyId(string $key): string
    {
        return bin2hex(self::rawKeyId($key));
    }

    private static function rawKeyId(string $key): string
    {
        return substr(sodium_crypto_generichash('juwri-backup-key-id'.$key, '', 32), 0, 8);
    }

    /** True when the file starts with the encrypted-dump magic. */
    public static function isEncrypted(string $file): bool
    {
        $h = @fopen($file, 'rb');
        if (! $h) {
            return false;
        }
        $head = (string) fread($h, 4);
        fclose($h);

        return $head === self::MAGIC;
    }

    /** Key id recorded in an encrypted file's header, or null when not encrypted / unreadable. */
    public static function fileKeyId(string $file): ?string
    {
        $h = @fopen($file, 'rb');
        if (! $h) {
            return null;
        }
        $head = (string) fread($h, self::HEADER_LEN);
        fclose($h);
        if (strlen($head) !== self::HEADER_LEN || substr($head, 0, 4) !== self::MAGIC) {
            return null;
        }

        return bin2hex(substr($head, 5, 8));
    }

    /** Encrypt $in to $out, streaming. $out is created 0600. */
    public static function encryptFile(string $in, string $out, string $key): void
    {
        $src = @fopen($in, 'rb');
        if (! $src) {
            throw new RuntimeException('backup encryption: cannot read '.basename($in));
        }
        $old = umask(0077);
        $dst = @fopen($out, 'wb');
        umask($old);
        if (! $dst) {
            fclose($src);
            throw new RuntimeException('backup encryption: cannot write '.basename($out));
        }

        try {
            $header = self::MAGIC.chr(self::VERSION).self::rawKeyId($key);
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            self::write($dst, $header.$streamHeader);

            $chunk = self::readChunk($src);
            while (true) {
                $next = feof($src) ? '' : self::readChunk($src);
                $final = $next === '' && feof($src);
                $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $c = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, $header, $tag);
                self::write($dst, pack('N', strlen($c)).$c);
                if ($final) {
                    break;
                }
                $chunk = $next;
            }
            if (! fflush($dst)) {
                throw new RuntimeException('backup encryption: flush failed');
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    /**
     * Decrypt $in to $out, streaming, trying every configured key whose id matches. Throws (and removes $out) on a
     * missing/wrong key, tampering or truncation, so a caller never holds a partial plaintext.
     */
    public static function decryptFile(string $in, string $out): void
    {
        $key = self::keyFor($in);
        $old = umask(0077);
        $dst = @fopen($out, 'wb');
        umask($old);
        if (! $dst) {
            throw new RuntimeException('backup decryption: cannot write '.basename($out));
        }
        try {
            self::decryptStream($in, $key, $dst);
            fclose($dst);
        } catch (\Throwable $e) {
            fclose($dst);
            @unlink($out);
            throw $e;
        }
    }

    /** Authenticate the whole file without writing plaintext anywhere. */
    public static function verifyFile(string $in): void
    {
        self::decryptStream($in, self::keyFor($in), null);
    }

    /**
     * P6-96b: exact size of the encrypted form of a $plainBytes-long file (header + one 4-byte length + ABYTES per
     * 64 KiB frame, at least one frame). Lets the media mirror stay incremental without downloading anything.
     */
    public static function encryptedSize(int $plainBytes): int
    {
        $frames = max(1, intdiv($plainBytes + self::CHUNK - 1, self::CHUNK));

        return self::HEADER_LEN + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            + $frames * (4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) + $plainBytes;
    }

    /**
     * P6-96b: encrypt a stored file in place (temp file + atomic rename) with the current key. No key → file left
     * as is and false returned (backward compatible). The plaintext never stays next to the sealed copy.
     */
    public static function sealInPlace(string $path): bool
    {
        $key = self::currentKey();
        if ($key === null) {
            return false;
        }
        $tmp = $path.'.jwbk-tmp';
        try {
            self::encryptFile($path, $tmp, $key);
            if (! @rename($tmp, $path)) {
                throw new RuntimeException('backup encryption: cannot replace '.basename($path));
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        return true;
    }

    /**
     * P6-96b: a plaintext copy of a stored archive for download. Encrypted → fully decrypted AND authenticated into
     * a 0600 temp file under $tmpDir (caller deletes it after sending); plain → the file itself. Wrong key, tamper
     * or truncation throw — a partial or forged archive is never handed out.
     *
     * @return array{0: string, 1: bool} [path, isTemporary]
     */
    public static function plaintextCopy(string $path, string $tmpDir): array
    {
        if (! self::isEncrypted($path)) {
            return [$path, false];
        }
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }
        $out = rtrim($tmpDir, '/').'/dl-'.bin2hex(random_bytes(12));
        self::decryptFile($path, $out);

        return [$out, true];
    }

    private static function keyFor(string $in): string
    {
        $id = self::fileKeyId($in);
        if ($id === null) {
            throw new RuntimeException('backup decryption: '.basename($in).' is not an encrypted backup (bad header)');
        }
        $keys = self::allKeys();
        if ($keys === []) {
            throw new RuntimeException("backup decryption: {$id}-keyed dump but BACKUP_ENCRYPTION_KEY is not set");
        }
        foreach ($keys as $k) {
            if (hash_equals(self::keyId($k), $id)) {
                return $k;
            }
        }

        throw new RuntimeException("backup decryption: wrong key — the dump was encrypted with key id {$id}, configured key id(s): "
            .implode(', ', array_map(self::keyId(...), $keys)).' (set the old key in BACKUP_ENCRYPTION_PREVIOUS_KEYS)');
    }

    /** @param resource|null $dst */
    private static function decryptStream(string $in, string $key, $dst): void
    {
        $src = @fopen($in, 'rb');
        if (! $src) {
            throw new RuntimeException('backup decryption: cannot read '.basename($in));
        }
        try {
            $header = self::readExact($src, self::HEADER_LEN);
            if (substr($header, 0, 4) !== self::MAGIC || ord($header[4]) !== self::VERSION) {
                throw new RuntimeException('backup decryption: unsupported format/version');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                self::readExact($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);

            $maxFrame = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            while (true) {
                $len = self::readExact($src, 4, true);
                if ($len === '') {
                    throw new RuntimeException('backup decryption: truncated (no final frame)');
                }
                $n = (int) unpack('N', $len)[1];
                if ($n < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n > $maxFrame) {
                    throw new RuntimeException('backup decryption: corrupt frame length');
                }
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::readExact($src, $n), $header);
                if ($res === false) {
                    throw new RuntimeException('backup decryption: authentication failed (tampered, corrupt or wrong key)');
                }
                [$plain, $tag] = $res;
                if ($dst !== null && $plain !== '') {
                    self::write($dst, $plain);
                }
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if (fread($src, 1) !== '') {
                        throw new RuntimeException('backup decryption: trailing data after the final frame');
                    }

                    return;
                }
            }
        } finally {
            fclose($src);
        }
    }

    /**
     * Up to CHUNK bytes, looping over short reads (non-plain streams return 8 KiB at a time), so every frame but the
     * last is exactly CHUNK long and {@see encryptedSize()} holds for any source.
     *
     * @param  resource  $h
     */
    private static function readChunk($h): string
    {
        $buf = '';
        while (strlen($buf) < self::CHUNK && ! feof($h)) {
            $part = fread($h, self::CHUNK - strlen($buf));
            if ($part === false) {
                throw new RuntimeException('backup encryption: read failed');
            }
            $buf .= $part;
        }

        return $buf;
    }

    /** @param resource $h */
    private static function readExact($h, int $n, bool $eofOk = false): string
    {
        $buf = '';
        while (strlen($buf) < $n && ! feof($h)) {
            $part = fread($h, $n - strlen($buf));
            if ($part === false) {
                break;
            }
            $buf .= $part;
        }
        if ($eofOk && $buf === '') {
            return '';
        }
        if (strlen($buf) !== $n) {
            throw new RuntimeException('backup decryption: truncated file');
        }

        return $buf;
    }

    /** @param resource $h */
    private static function write($h, string $data): void
    {
        $len = strlen($data);
        $done = 0;
        while ($done < $len) {
            $w = fwrite($h, substr($data, $done));
            if ($w === false || $w === 0) {
                throw new RuntimeException('backup crypto: write failed (disk full?)');
            }
            $done += $w;
        }
    }
}
