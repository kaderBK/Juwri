<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Webhooks;

use Closure;
use Juwri\Core\Contracts\Webhooks\WebhookDeduplication;

/** J25-7 part 4: {@see WebhookDeduplication} → {@see WebhookReceipts} (pure delegation). */
final class WebhookDeduplicationApi implements WebhookDeduplication
{
    public function once(string $provider, int|string|null $eventId, Closure $process, mixed $duplicate = null): mixed
    {
        return WebhookReceipts::once($provider, $eventId, $process, $duplicate);
    }
}
