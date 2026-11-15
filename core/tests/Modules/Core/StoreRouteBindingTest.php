<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Store::resolveRouteBinding must only resolve `{store}` by slug on public
 * storefront routes; owner/dashboard/admin/API routes stay id-only.
 */
class StoreRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    /** @return array{User, Store} */
    private function makeOwnerAndStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'store-'.Str::lower(Str::random(6)),
            'color' => '#111',
            'title' => 'Test Store',
            'sub_title' => 'Sub',
            'description' => 'Desc',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    public function test_owner_routes_reject_a_slug_and_a_foreign_store_id(): void
    {
        [$owner, $ownStore] = $this->makeOwnerAndStore();
        [, $foreignStore] = $this->makeOwnerAndStore();

        // Slug on an owner route must be rejected (404, not resolved by slug).
        $this->actingAs($owner)
            ->get(route('stores.whatsappSettings', ['store' => $ownStore->slug]))
            ->assertNotFound();

        // Foreign store id on an owner route must be rejected (403 or 404).
        $foreignRes = $this->actingAs($owner)
            ->get(route('stores.whatsappSettings', ['store' => $foreignStore->id]));
        $this->assertContains($foreignRes->status(), [403, 404]);

        // Own store id succeeds.
        $this->actingAs($owner)
            ->get(route('stores.whatsappSettings', ['store' => $ownStore->id]))
            ->assertOk();
    }

    public function test_public_storefront_route_accepts_the_slug(): void
    {
        [, $store] = $this->makeOwnerAndStore();
        StoreOption::updateStoreOption($store->id, 'gift_cards_enabled', '1');

        $this->get(route('store.giftcards.buy', ['store' => $store->slug]))
            ->assertOk();
    }

    public function test_localized_public_storefront_route_accepts_the_slug(): void
    {
        [, $store] = $this->makeOwnerAndStore();

        Route::middleware('web')->get('{locale}/{store}/_test-bind', fn (string $locale, Store $store) => response()->json(['id' => $store->id]));

        $this->getJson('/ar/'.$store->slug.'/_test-bind')
            ->assertOk()
            ->assertJsonPath('id', $store->id);
    }

    public function test_a_new_store_never_gets_an_all_digit_slug(): void
    {
        [$owner] = $this->makeOwnerAndStore();

        $store = Store::create([
            'user_id' => $owner->id, 'slug' => '12345', 'color' => '#111', 'title' => '12345',
            'sub_title' => 'Sub', 'description' => 'Desc', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);

        $this->assertFalse(ctype_digit((string) $store->slug));
        $this->assertStringStartsWith('store-', (string) $store->slug);
    }
}
