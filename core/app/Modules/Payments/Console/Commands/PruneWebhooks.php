<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Payments\Services\Webhooks\SendOnce;
use Juwri\Modules\Payments\Services\Webhooks\TransactionalOutbox;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;

/**
 * J25-7: delete webhook receipts and performed outbox messages older than the retention period (default 30 days,
 * never less than 7 — a provider may redeliver an event for days). Pending and failed outbox messages are kept.
 */
class PruneWebhooks extends Command
{
    protected $signature = 'payments:prune-webhooks {--days= : retention in days (minimum 7)}';

    protected $description = 'Delete old webhook receipts and performed outbox messages.';

    public function handle(): int
    {
        $days = max(WebhookReceipts::MIN_RETENTION_DAYS, (int) ($this->option('days') ?: WebhookReceipts::DEFAULT_RETENTION_DAYS));
        $receipts = WebhookReceipts::prune($days);
        $messages = TransactionalOutbox::prune($days);
        $markers = SendOnce::prune($days); // P6-158: confirmed send markers, same retention as the outbox
        $this->info("Pruned {$receipts} webhook receipt(s), {$messages} outbox message(s) and {$markers} send marker(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
