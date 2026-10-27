<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta;

/**
 * TAX-RUN E-P1: ETA (Egypt) e-Receipt canonicalization, per the SDK-documented
 * serialization used to compute the receipt uuid:
 *
 *   - For each property: emit its name wrapped in double quotes.
 *   - Scalar value: emit the JSON-serialized value (strings quoted + escaped).
 *   - Object value: recurse.
 *   - Array value: for EACH element, re-emit the property name (quoted) then
 *     recurse into the element.
 *
 * The uuid is the lowercase hex SHA-256 of this canonical string. Byte-exact
 * validation against official SDK sample documents is a deferred seam (needs the
 * SDK); the algorithm + determinism + previousUUID chaining are covered here.
 */
class EtaCanonicalizer
{
    public function canonicalize(array $data): string
    {
        $out = '';
        foreach ($data as $key => $value) {
            $out .= $this->serializeProperty((string) $key, $value);
        }

        return $out;
    }

    public function uuid(array $data): string
    {
        return hash('sha256', $this->canonicalize($data));
    }

    private function serializeProperty(string $name, $value): string
    {
        if (is_array($value)) {
            if ($this->isList($value)) {
                $out = '';
                foreach ($value as $element) {
                    $out .= '"' . $name . '"';
                    $out .= is_array($element) ? $this->canonicalize($element) : $this->scalar($element);
                }

                return $out;
            }

            // Associative → nested object.
            return '"' . $name . '"' . $this->canonicalize($value);
        }

        return '"' . $name . '"' . $this->scalar($value);
    }

    private function scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) json_encode($value);
        }

        return (string) json_encode((string) $value, JSON_UNESCAPED_SLASHES);
    }

    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
