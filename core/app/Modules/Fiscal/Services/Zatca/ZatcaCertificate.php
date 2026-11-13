<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Illuminate\Support\Carbon;

/** Helpers for the binarySecurityToken ZATCA returns: base64 of the base64 DER body of an X.509 certificate. */
final class ZatcaCertificate
{
    /** The certificate body (base64 DER) for the XML X509Certificate element. */
    public static function body(string $token): ?string
    {
        $body = base64_decode($token, true);

        return is_string($body) && $body !== '' && base64_decode($body, true) !== false && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $body) ? preg_replace('/\s+/', '', $body) : null;
    }

    /** Not-after date of the certificate, or null when the token is not a parseable certificate (fake clients, bad data). */
    public static function expiry(string $token): ?Carbon
    {
        $body = self::body($token);
        if ($body === null) {
            return null;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
        $info = @openssl_x509_parse($pem);

        return is_array($info) && isset($info['validTo_time_t']) ? Carbon::createFromTimestampUTC((int) $info['validTo_time_t']) : null;
    }

    /** PEM wrapper for a base64 DER certificate body. */
    public static function pem(string $body): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $body) ?? '', 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * P6-87: QR tag 9 — the certificate's own signature (the CA's ECDSA signature over the TBSCertificate), raw DER bytes.
     * ZATCA Security Features Implementation Standards, "QR code" table: tag 9 = "ECDSA signature of the cryptographic
     * stamp's public key by ZATCA's technical CA" — i.e. the signatureValue BIT STRING of the X.509 certificate.
     * Certificate ::= SEQUENCE { tbsCertificate SEQUENCE, signatureAlgorithm SEQUENCE, signatureValue BIT STRING }.
     */
    public static function signatureValue(string $body): ?string
    {
        $der = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
        if (! is_string($der) || $der === '') {
            return null;
        }
        $outer = self::tlv($der, 0);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $pos = $outer['start'];
        $tbs = self::tlv($der, $pos);
        $alg = $tbs ? self::tlv($der, $tbs['end']) : null;
        $sig = $alg ? self::tlv($der, $alg['end']) : null;
        if ($tbs === null || $tbs['tag'] !== 0x30 || $alg === null || $alg['tag'] !== 0x30 || $sig === null || $sig['tag'] !== 0x03 || $sig['len'] < 2) {
            return null;
        }

        // First content byte of a BIT STRING is the unused-bits count (0 for signatures).
        return substr($der, $sig['start'] + 1, $sig['len'] - 1);
    }

    /**
     * P6-87: what xades:SigningCertificate needs — issuer DN (RFC 4514 order as ZATCA samples print it, e.g.
     * "CN=TSZEINVOICE-SubCA-1, DC=extgazt, DC=gov, DC=local") and the serial number in decimal.
     *
     * @return array{issuer:string,serial:string}|null
     */
    public static function issuerSerial(string $body): ?array
    {
        $info = @openssl_x509_parse(self::pem($body), false);
        if (! is_array($info) || ! isset($info['issuer'], $info['serialNumber'])) {
            return null;
        }
        $parts = [];
        foreach ((array) $info['issuer'] as $k => $v) {
            foreach ((array) $v as $one) {
                $parts[] = self::shortName((string) $k).'='.$one;
            }
        }

        return ['issuer' => implode(', ', array_reverse($parts)), 'serial' => (string) $info['serialNumber']];
    }

    private static function shortName(string $long): string
    {
        return ['commonName' => 'CN', 'domainComponent' => 'DC', 'organizationName' => 'O', 'organizationalUnitName' => 'OU',
            'countryName' => 'C', 'localityName' => 'L', 'stateOrProvinceName' => 'ST', 'emailAddress' => 'E'][$long] ?? $long;
    }

    /** @return array{tag:int,len:int,start:int,end:int}|null one DER TLV at $pos */
    private static function tlv(string $der, int $pos): ?array
    {
        $n = strlen($der);
        if ($pos + 2 > $n) {
            return null;
        }
        $tag = ord($der[$pos]);
        $len = ord($der[$pos + 1]);
        $start = $pos + 2;
        if ($len & 0x80) {
            $bytes = $len & 0x7F;
            if ($bytes < 1 || $bytes > 4 || $start + $bytes > $n) {
                return null;
            }
            $len = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $len = ($len << 8) | ord($der[$start + $i]);
            }
            $start += $bytes;
        }
        if ($start + $len > $n) {
            return null;
        }

        return ['tag' => $tag, 'len' => $len, 'start' => $start, 'end' => $start + $len];
    }
}
