<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppWebhookProcessor;

/**
 * Processes a WhatsApp webhook payload off the request cycle so the controller
 * can return 200 within the provider's timeout. (spec §15.1)
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    /** P6-64: bounded retries; timeout stays below queue retry_after. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public function __construct(public string $provider, public array $payload) {}

    public function handle(WhatsAppWebhookProcessor $processor): void
    {
        $processor->handle($this->provider, $this->payload);
    }
}
