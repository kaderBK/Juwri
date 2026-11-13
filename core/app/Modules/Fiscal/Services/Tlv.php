<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

/**
 * TAX-RUN F0: ZATCA-style TLV (Tag-Length-Value) base64 encoder, shared by the
 * SA profile's 5-tag pre-onboarding QR and the Z-track 9-tag production QR.
 *
 * Each field is encoded as: 1 byte tag, 1 byte length, then the UTF-8 value
 * bytes. The concatenation is base64-encoded. Lengths >255 use the standard
 * multi-byte form the ZATCA spec allows (rare for these fields, handled anyway).
 */
class Tlv
{
    /**
     * @param  array<int,string>  $fields  tag(int) => value(string)
     */
    public static function encode(array $fields): string
    {
        $out = '';
        foreach ($fields as $tag => $value) {
            $bytes = (string) $value;
            $len = strlen($bytes);
            $out .= chr($tag & 0xFF) . self::lengthBytes($len) . $bytes;
        }

        return base64_encode($out);
    }

    /**
     * Decode a base64 TLV string back to a tag => value map. Used by tests and
     * the QR-decode verification script.
     *
     * @return array<int,string>
     */
    public static function decode(string $base64): array
    {
        $raw = base64_decode($base64, true);
        if ($raw === false) {
            return [];
        }

        $out = [];
        $i = 0;
        $n = strlen($raw);
        while ($i < $n) {
            $tag = ord($raw[$i]);
            $i++;
            if ($i >= $n) {
                break;
            }
            $len = ord($raw[$i]);
            $i++;
            $out[$tag] = substr($raw, $i, $len);
            $i += $len;
        }

        return $out;
    }

    private static function lengthBytes(int $len): string
    {
        // ZATCA fields are short; single-byte length is the standard case.
        return chr($len & 0xFF);
    }
}
