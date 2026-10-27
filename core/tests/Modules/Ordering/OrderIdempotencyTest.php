<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Services\OrderApiService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAP-2 (platform): a repeated client_uuid returns the existing order rather than
 * creating a duplicate (retry / offline-replay safety).
 */
class OrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_same_client_uuid_returns_existing_order(): void
    {
        $r = $this->storeFixture();
        $ownerId = (int) $r->user_id;
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        $payload = [
            'type' => 'takeaway',
            'client_uuid' => 'fixed-uuid-123',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ];

        $first = OrderApiService::create($ownerId, $r->id, $payload);
        $second = OrderApiService::create($ownerId, $r->id, $payload);

        $this->assertSame($first->id, $second->id, 'Repeat client_uuid must return the same order.');
        $this->assertSame(1, Order::where('store_id', $r->id)->count(), 'No duplicate order row.');
    }

    public function test_no_client_uuid_still_creates_distinct_orders(): void
    {
        $r = $this->storeFixture();
        $ownerId = (int) $r->user_id;
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        $payload = ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]];

        $a = OrderApiService::create($ownerId, $r->id, $payload);
        $b = OrderApiService::create($ownerId, $r->id, $payload);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Order::where('store_id', $r->id)->count());
    }

    public function test_same_uuid_different_restaurants_do_not_collide(): void
    {
        $r1 = $this->storeFixture();
        $r2 = $this->storeFixture();
        $i1 = Menu::create(['store_id' => $r1->id, 'name' => 'A', 'price' => 5, 'active' => 1]);
        $i2 = Menu::create(['store_id' => $r2->id, 'name' => 'B', 'price' => 5, 'active' => 1]);

        $o1 = OrderApiService::create((int) $r1->user_id, $r1->id, [
            'type' => 'takeaway', 'client_uuid' => 'dup', 'items' => [['item_id' => $i1->id, 'quantity' => 1]],
        ]);
        $o2 = OrderApiService::create((int) $r2->user_id, $r2->id, [
            'type' => 'takeaway', 'client_uuid' => 'dup', 'items' => [['item_id' => $i2->id, 'quantity' => 1]],
        ]);

        $this->assertNotSame($o1->id, $o2->id, 'Same uuid across restaurants must not collide.');
    }

    // ------------------------------------------------------------------ A2

    public function test_unique_index_on_restaurant_client_uuid_exists(): void
    {
        $this->assertTrue(
            Schema::hasIndex('orders', 'orders_rid_cuuid_uniq'),
            'The race backstop unique index orders_rid_cuuid_uniq must exist.'
        );
    }

    public function test_db_rejects_a_duplicate_restaurant_client_uuid(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $payload = ['type' => 'takeaway', 'client_uuid' => 'race-1', 'items' => [['item_id' => $item->id, 'quantity' => 1]]];

        OrderApiService::create((int) $r->user_id, $r->id, $payload);

        // A raw duplicate insert (the concurrent-retry loser) must be rejected by the
        // unique index — this is exactly what OrderApiService::create catches.
        $this->expectException(QueryException::class);
        Order::create([
            'store_id' => $r->id, 'client_uuid' => 'race-1', 'type' => 'takeaway',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_idempotency_key_header_dedupes_on_owner_endpoint(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id, 'name' => 't',
            'token' => hash('sha256', $raw),
            'abilities' => ['read', 'write', 'orders:read', 'orders:write'],
        ]);

        $body = ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]];
        $headers = ['Authorization' => 'Bearer '.$raw, 'Idempotency-Key' => '0190f3a2-7b1c-7def-8abc-0000000abc01'];
        // before J27-A2: 'idem-abc' — the key must now be a UUID (422 otherwise, see ClientOrderIdTest).

        $first = $this->postJson("/api/v1/stores/{$r->id}/orders", $body, $headers)->assertStatus(201);
        $second = $this->postJson("/api/v1/stores/{$r->id}/orders", $body, $headers)->assertStatus(201);

        // The owner endpoint had NO dedupe before A2 — the header must now make it idempotent.
        $this->assertSame($first->json('id'), $second->json('id'), 'Same Idempotency-Key must return the same order.');
        $this->assertSame(1, Order::where('store_id', $r->id)->count(), 'No duplicate order row.');
    }
}
