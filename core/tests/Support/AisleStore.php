<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\StoreOption;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\StorefrontApi\ApiFixtures;

/**
 * P3-08 test helper: Dune & Co. (the retail demo of the kit API examples: a shirt in Colour x Size with stock per variant,
 * trousers, a shipping zone with Standard / Express / pick up in store, returns window 14 days) with the `aisle` theme on,
 * its owner able to take orders. Prices SAR 139 / 229, store time Asia/Riyadh.
 */
final class AisleStore
{
    public ApiFixtures $fx;

    public Store $store;

    public static function make(string $theme = 'aisle'): self
    {
        $me = new self;
        $me->fx = new ApiFixtures;
        $me->store = $me->fx->duneRetail();
        User::whereKey($me->store->user_id)->update(['status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $me->store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($me->store->id, 'store_template', $theme);
        StoreSettings::setMany((int) $me->store->id, ['fiscal_country' => 'SA']);

        return $me;
    }
}
