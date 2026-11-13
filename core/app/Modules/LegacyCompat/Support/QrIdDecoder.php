<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

/**
 * J52b: the old printed-QR "qr-id" query value (LEGACYCOMPAT-SPEC §4, plan §5.2), reimplemented from the documented
 * behaviour, not copied:
 *
 * - wire form: standard Base64 (alphabet `+/`, `=` padding) of plaintext XOR keystream; plaintext = the store slug
 *   (or the numeric store id for id-style codes);
 * - keystream: chunks appended until it covers the data, then truncated; each chunk is the first n bytes of the
 *   binary SHA-1 of (passphrase . keystream so far); n = 2 for data of at most 100 bytes, 8 for longer data;
 * - passphrases: two are in circulation (the original platform's and the later one); both come from config
 *   `legacy_compat.qr_passphrases`, never from code.
 *
 * SECURITY: the passphrases are not secret, so anyone can build a qr-id for any slug. A match may only count a scan
 * and redirect to the public storefront; it must never set a table, a room, a session flag or any privilege
 * (coordinator rule, J52b security review).
 */
final class QrIdDecoder
{
    /** Bytes of data up to which a keystream chunk is 2 bytes long; longer data uses 8-byte chunks. */
    public const SHORT_DATA_MAX = 100;

    /** Longer query values are never a printed code (slugs are short); refuse them before any work. */
    public const MAX_QR_ID_LENGTH = 1024;

    /** @param list<string>|null $passphrases null = read config legacy_compat.qr_passphrases */
    public function __construct(private readonly ?array $passphrases = null) {}

    /** @return list<string> */
    public function passphrases(): array
    {
        $list = $this->passphrases ?? config('legacy_compat.qr_passphrases', []);

        return array_values(array_filter(
            is_array($list) ? $list : [],
            static fn (mixed $p): bool => is_string($p) && $p !== ''
        ));
    }

    /** The keystream of $length bytes for $passphrase (documented chunked SHA-1 construction). */
    public static function keystream(string $passphrase, int $length): string
    {
        $chunk = $length > self::SHORT_DATA_MAX ? 8 : 2;
        $stream = '';
        while (strlen($stream) < $length) {
            $stream .= substr(sha1($passphrase.$stream, true), 0, $chunk);
        }

        return substr($stream, 0, $length);
    }

    /** Byte-wise XOR with the keystream; its own inverse. */
    public static function transform(string $data, string $passphrase): string
    {
        return $data === '' ? '' : $data ^ self::keystream($passphrase, strlen($data));
    }

    /** The qr-id for $plaintext (used for contract vectors and tests; new codes use Juwri's own format). */
    public static function encode(string $plaintext, string $passphrase): string
    {
        return base64_encode(self::transform($plaintext, $passphrase));
    }

    /** Plaintext of $qrId under one passphrase, or null when it is not valid Base64 or is empty. */
    public static function decodeWith(string $qrId, string $passphrase): ?string
    {
        if ($qrId === '' || strlen($qrId) > self::MAX_QR_ID_LENGTH) {
            return null;
        }

        // A printed URL whose "+" was not percent-encoded reaches us as a space after query parsing.
        $raw = base64_decode(str_replace(' ', '+', $qrId), true);
        if ($raw === false || $raw === '') {
            return null;
        }

        return self::transform($raw, $passphrase);
    }

    /**
     * True when $qrId decodes to exactly $pathSegment (byte-for-byte, case kept) under any configured passphrase.
     * Anything that is not a string (?qr-id[]=x), bad Base64 or a different plaintext is simply no match.
     */
    public function matches(mixed $qrId, string $pathSegment): bool
    {
        if (! is_string($qrId) || $pathSegment === '') {
            return false;
        }

        foreach ($this->passphrases() as $passphrase) {
            $plain = self::decodeWith($qrId, $passphrase);
            if ($plain !== null && hash_equals($pathSegment, $plain)) {
                return true;
            }
        }

        return false;
    }
}
