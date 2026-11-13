<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Modules\Messaging\Services\SmsService;

/**
 * Queued single SMS send (order confirmations, status updates, etc.). Never
 * block a web request on a gateway call.
 */
class SendSmsJob implements ShouldQueue
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
    ) {}

    public function handle(SmsService $sms): void
    {
        if (trim($this->to) === '') {
            return;
        }
        $sms->send($this->to, $this->message, $this->storeId, $this->feature, $this->marketing);
    }
}
