<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\StoreOption;
use Carbon\Carbon;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\StorefrontApi\ApiFixtures;

/**
 * P3-09 test helper: Fresh Mart (the grocery demo of the kit API examples: Bananas 1 kg pack, Strawberries 250 g, milk 2 L,
 * Sourdough 800 g, Tomatoes sold by weight in 250 g to 2 kg steps with a 10 % cap, the "If it's out of stock" choice with
 * Replace / Call me / Remove, delivery slots 11:00-17:00 with 8 seats an hour, express SAR 5, delivery fee SAR 9, Stripe-like
 * online payment on) with the `basket` theme on, its owner able to take orders. Store time Asia/Riyadh; the example clock is
 * Fri 2 Oct 2026 10:45 there ({@see self::travel()}).
 */
final class BasketStore
{
    public ApiFixtures $fx;

    public Store $store;

    public static function make(string $theme = 'basket'): self
    {
        $me = new self;
        $me->fx = new ApiFixtures;
        $me->store = $me->fx->freshMartGrocery();
        User::whereKey($me->store->user_id)->update(['status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $me->store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($me->store->id, 'store_template', $theme);
        StoreSettings::setMany((int) $me->store->id, ['fiscal_country' => 'SA']);

        return $me;
    }

    /** Fri 2 Oct 2026 10:45 in Riyadh (the examples' clock): inside the store's hours, before the first slot of the day. */
    public static function clock(): Carbon
    {
        return Carbon::parse('2026-10-02 07:45:30', 'UTC');
    }
}
