<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Jobs;

use App\Services\MerchantCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a bulk email campaign to the merchants.
 *
 * Dispatched from the admin action so the request returns immediately: the
 * segment is walked, the mails are queued with a delay, and every recipient
 * gets a row in merchant_campaign_sends.
 */
class DispatchMerchantCampaign implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    /** @var array<int, int> */
    public array $backoff = [120];

    public function uniqueId(): string
    {
        return $this->campaign;
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function __construct(
        public array $criteria,
        public string $campaign,
        public ?string $subject = null,
        public ?string $body = null,
        public int $perMinute = 60,
    ) {
        $this->onQueue('heavy'); // QueueRoutingServiceProvider: heavy lane (a whole segment is walked)
    }

    public function handle(MerchantCampaign $campaign): void
    {
        $campaign->send($this->criteria, $this->campaign, $this->subject, $this->body, false, $this->perMinute);
    }
}
