<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner-decision #2 — the non-India half of the 86-toggle → aggregator bridge.
 *
 * Before this, flipping a menu item's is_sold_out fanned out ONLY to Zomato/Swiggy
 * (IndiaAggregatorSync). A connected Deliverect store heard nothing, so it kept taking
 * orders on 86'd items. AggregatorAvailabilityPusher now rides the same Menu hook and
 * pushes availability to non-India providers that support it (Deliverect today; its
 * snoozeProduct persists desired state locally, live Store-API call deferred to creds).
 *
 * These prove the SEAM (model save → pusher → provider), not snoozeProduct in isolation
 * (DeliverectPosTrackTest already covers the direct call).
 */
class AggregatorAvailabilitySyncTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Menu} */
    private function storeWithItem(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish',
            'price' => 10, 'active' => '1', 'is_sold_out' => false,
        ]);

        return [$r, $item];
    }

    public function test_86ing_an_item_snoozes_it_on_a_connected_deliverect_channel(): void
    {
        [$r, $item] = $this->storeWithItem();
        $conn = AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-1', 'status' => 'active',
        ]);

        // 86 the item — the model hook must fan the flip out to Deliverect.
        $item->update(['is_sold_out' => true]);

        $snoozed = $conn->fresh()->settings['snoozed_plus'] ?? [];
        $this->assertTrue((bool) ($snoozed[(string) $item->id] ?? false), '86 snoozed the item on Deliverect');

        // Un-86 — the item must be un-snoozed again.
        $item->update(['is_sold_out' => false]);

        $snoozed = $conn->fresh()->settings['snoozed_plus'] ?? [];
        $this->assertArrayNotHasKey((string) $item->id, $snoozed, 'restock un-snoozed the item');
    }

    public function test_only_the_is_sold_out_change_pushes_not_an_unrelated_edit(): void
    {
        [$r, $item] = $this->storeWithItem();
        $conn = AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-1', 'status' => 'active',
        ]);

        $item->update(['name' => 'Renamed Dish']); // no availability change

        $this->assertNull($conn->fresh()->settings['snoozed_plus'] ?? null, 'a name edit does not touch availability');
    }

    public function test_a_paused_channel_is_not_pushed(): void
    {
        [$r, $item] = $this->storeWithItem();
        $conn = AggregatorConnection::create([
            'provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-1', 'status' => 'paused',
        ]);

        $item->update(['is_sold_out' => true]);

        $this->assertNull($conn->fresh()->settings['snoozed_plus'] ?? null, 'an inactive channel is left alone');
    }

    public function test_a_provider_without_an_availability_endpoint_is_a_safe_noop(): void
    {
        [$r, $item] = $this->storeWithItem();
        // Uber Eats has no availability endpoint modelled — it must be skipped, not error.
        $conn = AggregatorConnection::create([
            'provider' => 'uber_eats', 'store_id' => $r->id, 'external_store_id' => 'UE-1', 'status' => 'active',
        ]);

        $item->update(['is_sold_out' => true]); // must not throw

        $this->assertNull($conn->fresh()->settings['snoozed_plus'] ?? null, 'no availability endpoint → nothing pushed');
        $this->assertTrue((bool) $item->fresh()->is_sold_out, 'the menu save itself still succeeded');
    }
}
