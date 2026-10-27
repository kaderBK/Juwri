<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * #101: Meta Conversions API (server-side Pixel). Sends a deduplicated event to
 * graph.facebook.com so conversions are tracked even when the browser Pixel is
 * blocked. `event_id` matches the client-side Pixel event so Meta dedupes them.
 *
 * No-op (logged) unless the restaurant has meta_pixel_id + meta_capi_token set.
 */
class SendMetaCapiEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(
        public int $storeId,
        public string $eventName,
        public string $eventId,
        public float $value = 0.0,
        public string $currency = 'USD',
    ) {}

    public function handle(): void
    {
        $pixelId = store_options($this->storeId, 'meta_pixel_id');
        $token = store_options($this->storeId, 'meta_capi_token');

        if (empty($pixelId) || empty($token)) {
            return; // Pixel/CAPI not configured for this restaurant
        }

        try {
            Http::asJson()->post("https://graph.facebook.com/v18.0/{$pixelId}/events", [
                'access_token' => $token,
                'data' => [[
                    'event_name' => $this->eventName,
                    'event_time' => time(),
                    'event_id' => $this->eventId,        // dedup key with the browser Pixel
                    'action_source' => 'website',
                    'custom_data' => [
                        'value' => round($this->value, 2),
                        'currency' => strtoupper($this->currency),
                    ],
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[meta capi] send failed: '.$e->getMessage());
        }
    }
}
