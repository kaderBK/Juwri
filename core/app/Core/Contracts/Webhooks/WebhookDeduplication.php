<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Webhooks;

use Closure;

/**
 * J25-7 part 4: webhook_receipts for modules above Payments (Studio top-ups …). Same semantics as
 * WebhookReceipts::once() (Payments module): call it AFTER the provider's verification, with an id taken from verified content;
 * a duplicate or concurrent copy gets $duplicate, a failing run (exception / HTTP ≥ 400) is released for the retry.
 */
interface WebhookDeduplication
{
    public function once(string $provider, int|string|null $eventId, Closure $process, mixed $duplicate = null): mixed;
}
