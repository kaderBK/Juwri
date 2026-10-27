<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;

/**
 * Queued single WhatsApp send (order confirmations, status updates, campaigns).
 * Business-initiated notifications should pass an approved $template so they're
 * accepted outside the 24h session window. Never blocks a web request.
 */
class SendWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public array $backoff = [60, 300];

    public function __construct(
        public string $to,
        public string $message,
        public ?int $storeId = null,
        public string $feature = 'general',
        public bool $marketing = false,
        public ?string $template = null,
        public array $vars = [],
    ) {}

    public function handle(WhatsAppService $wa): void
    {
        if (trim($this->to) === '') {
            return;
        }
        $wa->send($this->to, $this->message, $this->storeId, $this->feature, $this->marketing, $this->template, $this->vars);
    }
}
