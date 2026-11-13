<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Export\Contracts;

/**
 * TAX-RUN R-P2: qualified-signature seam for provider-gated countries (Tunisia
 * ANCE, Morocco DGI once specs publish). The real implementation talks to the
 * accredited CA / provider; the Fake returns a deterministic placeholder so the
 * export path is testable before the commercial subscription is active.
 */
interface QualifiedSigner
{
    /** Return a signed envelope (or base64 signature) for the given document. */
    public function sign(string $document, array $context = []): string;

    /** The signing authority label (e.g. ANCE, DGI). */
    public function authority(): string;
}
