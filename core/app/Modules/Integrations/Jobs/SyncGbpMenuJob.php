<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Integrations\Services\Gbp\GbpClientFactory;
use Juwri\Modules\Integrations\Services\Gbp\GbpMenuSyncService;

/**
 * google-partner Lane A / A5 — push one store's menu to its linked GBP location's
 * FoodMenus resource.
 *
 * Dark no-op unless (a) a gbp Credential exists with a linked location_id and (b) the
 * owner enabled gbp_menu_sync_enabled. updateFoodMenus is a full PATCH replace so the
 * job is naturally idempotent (running it twice yields the same resource). Fail-soft:
 * any client error is caught and recorded in secrets.sync_error; last_synced_at is
 * always stamped so the dashboard shows the last attempt.
 */
class SyncGbpMenuJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $storeId) {}

    public function handle(GbpMenuSyncService $mapper): void
    {
        $creds = Credential::for('gbp', $this->storeId);
        if (empty($creds['location_id']) || empty($creds['gbp_menu_sync_enabled'])) {
            return; // not linked or sync disabled → zero HTTP
        }

        $store = Store::find($this->storeId);
        if (! $store) {
            return;
        }

        $body = $mapper->build($store);

        try {
            GbpClientFactory::make($this->storeId)
                ->updateFoodMenus((string) $creds['location_id'], $body);
            $this->recordSync(null);
        } catch (\Throwable $e) {
            $this->recordSync($e->getMessage());
        }
    }

    /** Merge last_synced_at + sync_error (null clears) into the gbp Credential secrets. */
    private function recordSync(?string $error): void
    {
        $row = Credential::firstOrNew(['store_id' => $this->storeId, 'feature' => 'gbp']);
        $secrets = $row->secrets ?? [];
        $secrets['last_synced_at'] = Carbon::now()->toIso8601String();
        if ($error === null) {
            unset($secrets['sync_error']);
        } else {
            $secrets['sync_error'] = mb_substr($error, 0, 500);
        }
        $row->provider = 'gbp';
        $row->secrets = $secrets;
        $row->save();
    }
}
