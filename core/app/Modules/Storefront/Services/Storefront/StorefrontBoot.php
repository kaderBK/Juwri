<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use App\Services\FeatureAccess;
use App\Support\KioskContext;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;

/**
 * P3-03: the boot data of the storefront core JS (assets/storefront/core.js) — one JSON block per page, printed by
 * the `store_templates._partials.storefront-core` partial that kit layouts include. The view model's cheap subset
 * (ViewModelBuilder::boot) plus the per-store service worker registration and the menu snapshot URL.
 *
 * The service worker URL carries the EFFECTIVE offline-queue switch (`oq`, platform AND reseller AND owner, any off =
 * off). When a level flips it, the next page load registers a different worker URL, the browser installs it, and
 * that worker drops the queued orders of this store with a notice (the page's own boot data says the same, so
 * both ways end in the same place). Browsing and the cart offline stay on.
 */
final class StorefrontBoot
{
    public function __construct(
        private readonly ViewModelBuilder $builder,
        private readonly OfflineQueuePolicy $queue,
    ) {}

    /** @return array<string, mixed> */
    public function forStore(Store $store, ?string $table = null): array
    {
        $id = (int) $store->id;
        $kiosk = KioskContext::active($id)
            && FeatureAccess::storeAllowsCached($id, 'kiosk')
            && ! empty(store_options($id, 'kiosk_enabled'));

        $locale = app()->getLocale();
        $boot = $this->builder->boot(new ViewModelRequest(store: $store, locale: $locale, page: 'home', table: $table, kiosk: $kiosk));

        $boot['endpoints']['snapshot'] = route('store.snapshot', ['store' => $store->slug], false).'?l='.rawurlencode($locale);
        $boot['sw'] = [
            'url' => '/sw-store.js?'.http_build_query([
                'v' => (string) config('appinfo.version'),
                's' => (string) $store->slug,
                'oq' => $this->queue->enabled($store) ? '1' : '0',
            ]),
            'scope' => store_sw_scope($store),
        ];

        return $boot;
    }
}
