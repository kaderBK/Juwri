<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 (lane C, after P4-18): a gateway whose stores take payments on their OWN provider account (BYOK) and receive
 * that account's notifications on `webhook/{gateway}/store/{store}`. The adapter verifies with THAT store's webhook
 * secret only — never the platform's, never another store's — and settles only that store's own pending payments,
 * once. Before: those payments settled only when the buyer's browser came back (a closed tab = an unpaid order).
 */
interface StoreWebhooks
{
    public function handleStoreWebhook(Request $request, int $storeId): Response;
}
