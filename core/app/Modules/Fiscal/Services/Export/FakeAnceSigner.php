<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Export;

use Juwri\Modules\Fiscal\Services\Export\Contracts\QualifiedSigner;

/**
 * TAX-RUN R-P2: placeholder ANCE (Tunisia) qualified signer. Deterministic —
 * proves the TEIF signing seam end-to-end until the TTN/ANCE subscription lands
 * (deferred ledger).
 */
class FakeAnceSigner implements QualifiedSigner
{
    public function sign(string $document, array $context = []): string
    {
        return 'ANCE-SIG:' . base64_encode(hash('sha256', $document, true));
    }

    public function authority(): string
    {
        return 'ANCE';
    }
}
