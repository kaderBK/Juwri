<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A5-2 — the public menu API sends an ETag + Cache-Control and honours If-None-Match
 * with a 304; the ETag changes when the menu changes.
 */
class PublicMenuEtagTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        // WI-M8: the public menu API now gates on plan status, and User::plan() falls back to
        // the unseeded free_membership_plan config when group_id is absent. Give the owner a
        // real active plan so this ETag fixture keeps serving.
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1, 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);

        return $store;
    }

    public function test_public_menu_sends_etag_and_304s(): void
    {
        $store = $this->store();

        $first = $this->getJson('/api/v1/menu/'.$store->id)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('public', (string) $first->headers->get('Cache-Control'));

        $this->getJson('/api/v1/menu/'.$store->id, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_etag_changes_when_the_menu_changes(): void
    {
        $store = $this->store();
        $etag1 = $this->getJson('/api/v1/menu/'.$store->id)->headers->get('ETag');

        // Any menu write bumps the P5 cache version → the signature (ETag) changes.
        Menu::create(['store_id' => $store->id, 'name' => 'Fries', 'price' => 4, 'active' => 1]);

        $etag2 = $this->getJson('/api/v1/menu/'.$store->id)->headers->get('ETag');
        $this->assertNotSame($etag1, $etag2, 'a menu write must change the ETag');
    }
}
