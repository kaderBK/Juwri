<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Observers;

use Juwri\Modules\Menu\Jobs\NotifyFollowersJob;
use App\Models\MenuBanner;
use Juwri\Modules\Menu\Observers\MenuObserver;

/**
 * BRAND-ROW B3 — the "new offer" follower alert. A new active banner/offer is the
 * offer-side trigger; reuses MenuObserver::maybeNotify (same dark gate + daily
 * best-effort dedup; NotifyFollowersJob throttles authoritatively).
 */
class MenuBannerObserver
{
    public function created(MenuBanner $banner): void
    {
        if (! $banner->active) {
            return;
        }
        MenuObserver::maybeNotify((int) $banner->store_id, NotifyFollowersJob::EVENT_NEW_OFFER, (int) $banner->id);
    }
}
