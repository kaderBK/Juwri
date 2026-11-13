<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;

use App\Models\Order;
use Illuminate\Http\Client\Response;

/**
 * GAPS-RUN-2 B3-P1: accounting export contract. SyncInvoiceJob drives it — it owns
 * the bearer token, idempotency and retries; a provider only shapes the request
 * (endpoint + payload + headers) and reads the external id back. Behavior-preserving
 * refactor of the original inline QuickBooks call.
 */
interface AccountingProvider
{
    /** Provider key, e.g. 'quickbooks' | 'xero'. */
    public function key(): string;

    /** Full invoice endpoint for this connection (derived from stored base_url). */
    public function endpoint(array $conn): string;

    /** Extra request headers (e.g. Xero-tenant-id). Bearer is added by the job. */
    public function headers(array $conn): array;

    /** Provider-shaped invoice payload for an order. */
    public function buildPayload(Order $order): array;

    /** Extract the created invoice's external id from the response. */
    public function extractId(Response $response): string;
}
