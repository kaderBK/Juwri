<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use App\Services\FeatureAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientFactory;

/**
 * GOOGLE-PARTNER Lane B / B4: publish one Local Post to a store's Google Business
 * Profile.
 *
 * DARK: no-op unless the store has a connected GBP location AND the gbp_link
 * entitlement holds — the Fake client is used until F0 wires real creds, so a
 * creds-less environment makes zero HTTP calls. tries=1 (a Local Post is not
 * idempotent — never silently double-publish on retry); fail-soft on error.
 *
 * F0 WIRING (handshake B→A2): the real client's token comes from A2's refresh
 * helper at merge (GbpEngagementClient::token()).
 */
class CreateGbpPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** @param array<string,mixed> $body LocalPost API body (summary, callToAction, media, topicType). */
    public function __construct(public int $storeId, public array $body) {}

    public function handle(): void
    {
        $creds = Credential::for('gbp', $this->storeId);
        $locationId = $creds['location_id'] ?? null;
        if (empty($locationId)) {
            return; // not connected → zero behavior
        }
        if (! FeatureAccess::storeAllows($this->storeId, 'gbp_link')) {
            return; // kill-switch / entitlement veto
        }

        try {
            GbpEngagementClientFactory::make($this->storeId)
                ->createLocalPost((string) $locationId, $this->body);
        } catch (\Throwable $e) {
            Log::warning('gbp.posts.create_failed', [
                'store_id' => $this->storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
