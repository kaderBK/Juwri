<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\InApp;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * P5-17: verify a JWS signed by the App Store (StoreKit 2 signed transaction / renewal info, App Store Server
 * Notifications V2 signedPayload) with no network call:
 *  1. ES256, and an x5c chain of exactly three certificates (leaf, intermediate, root);
 *  2. the root is THE pinned Apple Root CA - G3 (SHA-256 of its DER, bundled in config) and is self-signed;
 *  3. the intermediate is signed by the root and carries Apple's WWDR marker OID, the leaf is signed by the intermediate
 *     and carries Apple's App Store receipt-signing OID; every certificate is within its validity period;
 *  4. the JWS signature verifies with the leaf's key.
 * Anything else throws InAppRejected — a forged or re-signed payload never reaches the billing code.
 */
final class AppleJws
{
    private const OID_LEAF = '1.2.840.113635.100.6.11.1';

    private const OID_INTERMEDIATE = '1.2.840.113635.100.6.2.1';

    /** @return array<string, mixed> the verified payload */
    public static function verify(string $jws, string $rootSha256): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new InAppRejected('invalid_jws');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'ES256' || ! is_array($header['x5c'] ?? null) || count($header['x5c']) !== 3) {
            throw new InAppRejected('invalid_jws');
        }
        [$leaf, $intermediate, $root] = array_map(fn ($c) => self::pem(is_string($c) ? $c : ''), $header['x5c']);
        $rootDer = base64_decode((string) $header['x5c'][2], true);
        if ($rootSha256 === '' || $rootDer === false || ! hash_equals(strtolower($rootSha256), hash('sha256', $rootDer))) {
            throw new InAppRejected('untrusted_root');
        }
        $now = time();
        foreach ([$leaf, $intermediate, $root] as $pem) {
            $info = openssl_x509_parse($pem);
            if (! is_array($info) || ($info['validFrom_time_t'] ?? PHP_INT_MAX) > $now || ($info['validTo_time_t'] ?? 0) < $now) {
                throw new InAppRejected('certificate_expired');
            }
        }
        $rootKey = openssl_pkey_get_public($root);
        $intermediateKey = openssl_pkey_get_public($intermediate);
        if ($rootKey === false || $intermediateKey === false
            || openssl_x509_verify($root, $rootKey) !== 1
            || openssl_x509_verify($intermediate, $rootKey) !== 1
            || openssl_x509_verify($leaf, $intermediateKey) !== 1) {
            throw new InAppRejected('broken_chain');
        }
        if (! self::hasOid($intermediate, self::OID_INTERMEDIATE) || ! self::hasOid($leaf, self::OID_LEAF)) {
            throw new InAppRejected('not_apple_certificate');
        }
        $leafKey = openssl_pkey_get_details(openssl_pkey_get_public($leaf) ?: throw new InAppRejected('broken_chain'));
        try {
            $payload = JWT::decode($jws, new Key((string) ($leafKey['key'] ?? ''), 'ES256'));
        } catch (Throwable) {
            throw new InAppRejected('bad_signature');
        }
        $out = json_decode((string) json_encode($payload), true);

        return is_array($out) ? $out : throw new InAppRejected('invalid_jws');
    }

    private static function pem(string $b64): string
    {
        if ($b64 === '' || base64_decode($b64, true) === false) {
            throw new InAppRejected('invalid_jws');
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($b64, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    private static function hasOid(string $pem, string $oid): bool
    {
        $info = openssl_x509_parse($pem, false);

        return is_array($info) && array_key_exists($oid, (array) ($info['extensions'] ?? []));
    }
}
