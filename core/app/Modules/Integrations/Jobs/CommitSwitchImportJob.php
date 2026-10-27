<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Integrations\Models\SwitchImport;
use Juwri\Modules\Integrations\Services\Switching\SwitchImports;

/**
 * P4-22b: commit a big switching import off the request (heavy queue). Unique per import (a second dispatch is
 * dropped) and SwitchImports::run() holds a cache lock, so it never imports twice. The payload is only two ids: the
 * job re-reads the store, checks the import belongs to it and to the store's account, and pins the tenant to that
 * one store before touching anything — a forged or stale payload finds nothing and does nothing.
 */
class CommitSwitchImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public int $importId, public int $storeId)
    {
        $this->onQueue('heavy');
    }

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(SwitchImports $imports): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }
        TenantContext::current()->actAs((int) $store->account_id, [(int) $store->id]);
        $imports->run($store, $this->importId);
    }

    public function failed(?\Throwable $e): void
    {
        $store = $this->store();
        if ($store !== null) {
            TenantContext::bypass(fn () => SwitchImport::query()->whereKey($this->importId)->where('store_id', $store->id)
                ->whereIn('status', ['queued', 'running'])
                ->update(['status' => 'failed', 'error' => mb_substr(___('The import stopped. The rows before it are imported; import again to continue.'), 0, 255)]));
        }
    }

    /** The store the import belongs to, read fresh (unscoped read, then checked: same store AND same account). */
    private function store(): ?Store
    {
        return TenantContext::bypass(function (): ?Store {
            $store = Store::query()->find($this->storeId);
            if ($store === null) {
                return null;
            }
            $owned = SwitchImport::query()->whereKey($this->importId)->where('store_id', $store->id)
                ->where('account_id', $store->account_id)->exists();

            return $owned ? $store : null;
        });
    }
}
