<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\PintAe\PintAeAdapter;

/**
 * J25-8 extension point: a Peppol access point of an FTA-accredited service provider (ASP) that exchanges a
 * UAE PINT AE e-invoice and reports it to the FTA. Certified e-invoicing network connectors are Community (owner,
 * 2026-10-03 — not ee): no implementation ships yet; a connector binds this interface and {@see PintAeAdapter}
 * forwards to it.
 */
interface PeppolAccessPoint
{
    /**
     * Send one document through the access point.
     *
     * Same result shape as {@see FiscalAdapter::submit()}.
     *
     * @return array{status: string, response?: array<string, mixed>, hash?: string, qr?: string, cleared_payload?: string}
     */
    public function send(FiscalDocument $document): array;
}
