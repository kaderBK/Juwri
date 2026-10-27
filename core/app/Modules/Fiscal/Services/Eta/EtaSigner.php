<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta;

use Juwri\Modules\Fiscal\Models\FiscalCertificate;

/**
 * TAX-RUN E-P1: ETA e-seal signing seam. Production uses an X.509 e-seal cert on
 * an HSM/token to produce a CAdES-BES signature over the 32-byte SHA-256 hash of
 * the canonicalized document. Sandbox accepts a soft cert. This class implements
 * the soft-cert path (openssl) and exposes the same interface a HSM adapter will
 * satisfy — the HSM/token path is a deferred seam.
 */
class EtaSigner
{
    /**
     * Sign the canonical document hash with the restaurant's e-seal soft cert.
     * Returns a base64 CAdES-BES-style signature value. When no cert is present
     * (un-onboarded), returns null so the caller can queue unsigned in sandbox.
     */
    public function sign(int $storeId, string $deviceKey, string $canonical): ?string
    {
        $cert = FiscalCertificate::resolve($storeId, 'eseal', $deviceKey);
        if (! $cert) {
            return null;
        }

        $key = openssl_pkey_get_private($cert->pem);
        if ($key === false) {
            return null;
        }

        // openssl_sign hashes its input with the given algo, so pass the canonical
        // content directly — signing a pre-computed digest would double-hash
        // (SHA256(SHA256(canonical))), which ETA CAdES-BES does not expect.
        $signature = '';
        if (! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return base64_encode($signature);
    }
}
