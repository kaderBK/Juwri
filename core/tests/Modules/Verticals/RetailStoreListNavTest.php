<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane A4 — the "Products" link on the store list card
 * (G1 full UI wiring: the retail catalog must be REACHABLE from the
 * dashboard, not just a bare route). A restaurant's "Edit menu" button must
 * render byte-identical to before.
 */
class RetailStoreListNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner, string $type): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_retail_store_card_links_to_the_products_dashboard(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'retail');

        $response = $this->actingAs($owner)->get(route('stores.index'));

        $response->assertOk();
        $response->assertSeeHtml(route('retail.products.index', $store->id));
        $response->assertDontSeeHtml(route('stores.menu', $store->id));
    }

    public function test_a_restaurant_card_still_links_to_edit_menu(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'restaurant');

        $response = $this->actingAs($owner)->get(route('stores.index'));

        $response->assertOk();
        $response->assertSeeHtml(route('stores.menu', $store->id));
        $response->assertDontSeeHtml(route('retail.products.index', $store->id));
    }
}
