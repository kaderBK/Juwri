<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

/**
 * Paytm's checksum ("signature") scheme, as documented for its payment APIs
 * (https://business.paytm.com/docs/checksum/).
 *
 * The signed text is either a raw string (the JSON `body` of an API call) or,
 * for form posts, the parameter values ordered by name and joined with "|"
 * (empty/"null" values count as empty strings). A 4-character random salt is
 * added: sha256("<text>|<salt>") in hex, followed by the salt, is encrypted
 * with AES-128-CBC under the merchant key (Paytm's fixed IV) and base64
 * encoded. Verifying decrypts the checksum, reads the salt back from its last
 * four characters and recomputes the hash.
 */
final class PaytmChecksum
{
    private const IV = '@@@@&&&&####$$$$';

    private const SALT_ALPHABET = 'AbcDE123IJKLMN67QRSTUVWXYZaBCdefghijklmn123opq45rs67tuv89wxyz0FGH45OP89';

    /** @param  string|array<string, mixed>  $payload */
    public static function generate(string|array $payload, string $merchantKey): string
    {
        $salt = self::salt();

        return (string) self::encrypt(self::hash(self::text($payload), $salt), $merchantKey);
    }

    /** @param  string|array<string, mixed>  $payload */
    public static function verify(string|array $payload, string $merchantKey, string $checksum): bool
    {
        if ($merchantKey === '' || $checksum === '') {
            return false;
        }
        if (is_array($payload)) {
            unset($payload['CHECKSUMHASH']);
        }

        $decrypted = self::decrypt($checksum, $merchantKey);
        if ($decrypted === null || strlen($decrypted) < 5) {
            return false;
        }

        return hash_equals(self::hash(self::text($payload), substr($decrypted, -4)), $decrypted);
    }

    /** @param  string|array<string, mixed>  $payload */
    private static function text(string|array $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }
        ksort($payload);

        return implode('|', array_map(
            fn ($value) => ($value === null || strtolower((string) $value) === 'null') ? '' : (string) $value,
            $payload,
        ));
    }

    private static function hash(string $text, string $salt): string
    {
        return hash('sha256', $text.'|'.$salt).$salt;
    }

    private static function salt(): string
    {
        $salt = '';
        for ($i = 0; $i < 4; $i++) {
            $salt .= self::SALT_ALPHABET[random_int(0, strlen(self::SALT_ALPHABET) - 1)];
        }

        return $salt;
    }

    private static function encrypt(string $text, string $key): string|false
    {
        return openssl_encrypt($text, 'AES-128-CBC', html_entity_decode($key), 0, self::IV);
    }

    private static function decrypt(string $cipher, string $key): ?string
    {
        $plain = openssl_decrypt($cipher, 'AES-128-CBC', html_entity_decode($key), 0, self::IV);

        return $plain === false ? null : $plain;
    }
}
