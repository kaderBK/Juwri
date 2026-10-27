<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AisleStore;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * Regression (found by the p3-aisle-retail e2e journey): `throttle:N,1` without a prefix shares ONE counter per IP between all such
 * routes, so a shopper's cart quotes used up the 10-a-minute allowance of the return request / back-in-stock sign-up and they got
 * "Too Many Attempts." Each retail route now counts on its own.
 */
class RetailThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_quotes_do_not_use_up_the_allowance_of_the_ten_a_minute_routes(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        AisleStore::make();
        $cart = ['order_type' => 'ship', 'lines' => [['item' => ApiFixtures::TROUSERS, 'qty' => 1, 'variant' => ApiFixtures::TROUSERS_NAVY_M]]];
        for ($i = 0; $i < 14; $i++) {
            $this->postJson('/dune-and-co-demo/retail/quote', $cart)->assertOk();
        }

        // an empty sign-up is refused for its content (422), never for traffic (429)
        $this->postJson('/dune-and-co-demo/stock-alerts', [])->assertStatus(422);
        $this->postJson('/order/track/not-a-token/returns', [])->assertNotFound();
    }
}
