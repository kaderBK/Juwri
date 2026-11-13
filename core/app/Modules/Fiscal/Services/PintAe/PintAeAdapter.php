<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\PintAe;

use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;
use Juwri\Modules\Fiscal\Contracts\PeppolAccessPoint;
use Juwri\Modules\Fiscal\Models\FiscalDocument;

/**
 * J25-8: UAE e-invoicing adapter STUB — Peppol PINT AE through an FTA-accredited service provider (5-corner model).
 * Voluntary from July 2026, mandatory from 2027 (large businesses Jan 2027, others Jul 2027 per the FTA timeline
 * in SPEC.md); B2C stays outside the mandate (AeProfile keeps the simplified receipt).
 *
 * Registered for 'AE' from config('fiscal.adapters.AE') but DISABLED by default (FISCAL_AE_PINT_ENABLED=false):
 * while disabled the manager has no AE adapter, so AE documents are never queued for submission (today's
 * behaviour). The network part is a {@see PeppolAccessPoint} connector (Community; none ships yet); without one bound, a submission is answered
 * `rejected` with a clear reason (never a silent "accepted"), and the document can be re-queued once a connector
 * exists. Building the PINT AE UBL payload is the follow-up that makes this adapter live.
 */
class PintAeAdapter implements FiscalAdapter
{
    public function __construct(private ?PeppolAccessPoint $accessPoint = null) {}

    public function country(): string
    {
        return 'AE';
    }

    public function mode(): string
    {
        return 'report_after_print';
    }

    /** @return array{status: string, response?: array<string, mixed>, hash?: string, qr?: string, cleared_payload?: string} */
    public function submit(FiscalDocument $document): array
    {
        if ($this->accessPoint === null) {
            return [
                'status' => 'rejected',
                'response' => [
                    'error' => 'pint_ae_access_point_missing',
                    'message' => 'UAE PINT AE needs an accredited service provider connector; none is configured.',
                ],
            ];
        }

        return $this->accessPoint->send($document);
    }
}
