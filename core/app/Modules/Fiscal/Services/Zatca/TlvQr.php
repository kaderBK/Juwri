<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Modules\Fiscal\Services\Tlv;

/**
 * TAX-RUN Z-P1: ZATCA base64 TLV QR builder.
 *
 * Tags 1-5 (pre-onboarding, simplified allowed with just these):
 *   1 seller name · 2 VAT registration number · 3 ISO-8601 timestamp ·
 *   4 invoice total (incl VAT) · 5 VAT amount.
 * Tags 6-9 (added once a device is onboarded to Phase 2, simplified only):
 *   6 SHA-256 invoice hash (base64) · 7 ECDSA signature (base64) ·
 *   8 cert public key (DER) · 9 ZATCA CA signature of the public key (see nineTag).
 */
class TlvQr
{
    /**
     * @param  array{seller:string,vat:string,timestamp:string,total:string,vat_amount:string}  $core
     */
    public static function fiveTag(array $core): string
    {
        return Tlv::encode([
            1 => $core['seller'],
            2 => $core['vat'],
            3 => $core['timestamp'],
            4 => $core['total'],
            5 => $core['vat_amount'],
        ]);
    }

    /**
     * P6-87 — ZATCA Security Features Implementation Standards, "QR Code" (Phase 2 tags), as the Fatoora SDK encodes them:
     *   6 invoice hash   — the base64 TEXT of the SHA-256 digest (44 bytes), as in ds:Reference/DigestValue
     *   7 ECDSA signature — the base64 TEXT of the signature, as in ds:SignatureValue
     *   8 public key     — raw DER bytes of the SubjectPublicKeyInfo
     *   9 CA signature   — raw DER bytes of the certificate's signatureValue ({@see ZatcaCertificate::signatureValue})
     * (before P6-87 tag 6 held the 32 raw digest bytes and tag 9 was always empty).
     *
     * $crypto: hash / signature / public_key base64, ca_signature raw bytes.
     *
     * @param  array{seller:string,vat:string,timestamp:string,total:string,vat_amount:string}  $core
     * @param  array{hash:string,signature:string,public_key:string,ca_signature?:string}  $crypto
     */
    public static function nineTag(array $core, array $crypto): string
    {
        return Tlv::encode([
            1 => $core['seller'],
            2 => $core['vat'],
            3 => $core['timestamp'],
            4 => $core['total'],
            5 => $core['vat_amount'],
            6 => $crypto['hash'],
            7 => $crypto['signature'],
            8 => self::rawBytes($crypto['public_key']),
            9 => $crypto['ca_signature'] ?? '',
        ]);
    }

    /** Decode a base64 value to raw bytes; passes through if it isn't valid base64. */
    private static function rawBytes(string $value): string
    {
        $raw = base64_decode($value, true);

        return $raw === false ? $value : $raw;
    }

    /** Decode helper for verification/tests. */
    public static function decode(string $base64): array
    {
        return Tlv::decode($base64);
    }
}
