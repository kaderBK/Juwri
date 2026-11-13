<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

use Juwri\Modules\Fiscal\Models\FiscalDocument;

/**
 * An adapter that can refuse to send a document before any call to the authority (device not onboarded, certificate
 * missing or expired, wrong gateway). The submit job asks first: a held document stays queued, nothing is sent, the
 * platform gets one alert, and the job does not retry (retrying cannot fix a missing certificate).
 */
interface GuardsSubmission
{
    /**
     * Null when the document may be sent; otherwise a stable reason code and an English alert text.
     *
     * @return array{reason: string, message: string}|null
     */
    public function holdReason(FiscalDocument $document): ?array;
}
