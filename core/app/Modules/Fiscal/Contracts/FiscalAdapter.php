<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;

/**
 * J25-8: one adapter per country (ZATCA Phase 2 for SA, JoFotara for JO, ETA for EG, Peppol PINT AE for AE —
 * stub, Oman later). An adapter is a REPORTING/CLASSIFICATION layer over an already computed sale: the
 * country's document service builds the authority payload from a {@see FiscalReceiptContext}
 * (never from an order); the adapter submits it and maps the answer onto the document's status machine. It never
 * touches order money.
 *
 * Adapters are registered per country from config('fiscal.adapters') by the Fiscal provider into
 * {@see FiscalDriverManager}; a certified network connector (Community — owner, 2026-10-03)
 * replaces a country's adapter by config, not by code changes here.
 */
interface FiscalAdapter
{
    /** Two-letter country code this adapter serves. */
    public function country(): string;

    /** Adapter mode: clear_before_print | report_after_print | none. */
    public function mode(): string;

    /**
     * Submit (report or clear) a queued document to the authority (or its Fake).
     *
     * Result: `status` accepted|rejected|submitted; `response` the raw authority response to snapshot; `hash` this
     * document's sealing hash (PIH source); `qr` an authority-returned QR (JoFotara/ETA); `cleared_payload` the
     * stamped/cleared payload for clear-before-print.
     *
     * @return array{status: string, response?: array<string, mixed>, hash?: string, qr?: string, cleared_payload?: string}
     */
    public function submit(FiscalDocument $document): array;
}
