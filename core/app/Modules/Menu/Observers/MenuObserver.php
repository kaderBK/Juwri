<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Observers;

use Illuminate\Support\Facades\Cache;
use Juwri\Modules\Menu\Jobs\NotifyFollowersJob;
use Juwri\Modules\Menu\Models\Menu;

/**
 * BRAND-ROW B3 — the single choke point for the "new dish" follower alert. Every
 * menu-item create path (dashboard, REST/staff API, MCP, POS/CSV import) funnels
 * through Menu::created, so the trigger lives here once instead of at each caller.
 */
class MenuObserver
{
    public function created(Menu $menu): void
    {
        // active is stored as the string '1'.
        if ((string) $menu->active !== '1') {
            return;
        }
        self::maybeNotify((int) $menu->store_id, NotifyFollowersJob::EVENT_NEW_ITEM, (int) $menu->id);
    }

    /**
     * Dispatch only when the store opted in (dark gate) and no fan-out has already
     * been claimed today — a best-effort guard that keeps a bulk import (hundreds of
     * Menu::created events) from queuing hundreds of jobs. NotifyFollowersJob still
     * enforces the gate and the daily throttle authoritatively.
     */
    public static function maybeNotify(int $storeId, string $event, int $subjectId): void
    {
        if ($storeId <= 0) {
            return;
        }
        if (! store_options($storeId, 'follower_notify_enabled')) {
            return;
        }
        if (Cache::has('follower_notify:'.$storeId.':'.now()->format('Ymd'))) {
            return;
        }
        NotifyFollowersJob::dispatch($storeId, $event, $subjectId);
    }
}
