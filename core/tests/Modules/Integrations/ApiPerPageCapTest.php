<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Reservation;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\TestCase;

/**
 * A5-3 — v1 list endpoints honour a capped `?per_page` (default when absent, max 100),
 * and the owner orders list supports `updated_since` delta polling.
 */
class ApiPerPageCapTest extends TestCase
{
    use RefreshDatabase;

    private function auth(User $owner): array
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw),
            'abilities' => ['read', 'restaurants:read', 'orders:read'],
        ]);

        return ['Authorization' => 'Bearer '.$raw];
    }

    public function test_per_page_is_honoured_and_capped(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $h = $this->auth($owner);

        $this->getJson('/api/v1/stores?per_page=5', $h)->assertOk()->assertJsonPath('per_page', 5);
        $this->getJson('/api/v1/stores?per_page=9999', $h)->assertOk()->assertJsonPath('per_page', 100);
        $this->getJson('/api/v1/stores', $h)->assertOk()->assertJsonPath('per_page', 20); // default
    }

    public function test_orders_since_delta_filter(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $h = $this->auth($owner);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $old = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'created_at' => now()->subDays(10)]);
        $recent = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'created_at' => now()]);

        $since = now()->subDay()->toDateTimeString();
        $res = $this->getJson('/api/v1/stores/'.$store->id.'/orders?since='.urlencode($since), $h)->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids, 'orders created before ?since are excluded');
    }

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** F0-4: same-second orders keep a deterministic newest-first order by id. */
    public function test_orders_newest_first_uses_a_stable_id_tiebreak(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $h = $this->auth($owner);
        $store = $this->storeFixture($owner);

        $at = now();
        $a = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'created_at' => $at]);
        $b = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'created_at' => $at]);
        $c = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'created_at' => $at]);

        $ids = collect($this->getJson('/api/v1/stores/'.$store->id.'/orders', $h)->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertSame([$c->id, $b->id, $a->id], $ids, 'orders with an equal created_at must order by id desc');
    }

    /**
     * F0-4: the customers list honours per_page (default 20), and `?sort=created`
     * surfaces a brand-new customer (null last_order_at) the "New Customer" polling
     * trigger would otherwise never see under the default most-recently-active sort.
     */
    public function test_customers_index_honours_per_page_and_created_sort(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $h = $this->auth($owner);
        $store = $this->storeFixture($owner);

        // The customers list is gated feature:crm_suite — grant it for the owner.
        config(['settings.admin_allow_crm_suite' => true]);
        EntitlementOverride::create([
            'user_id' => $owner->id, 'feature_key' => 'crm_suite', 'value' => '1', 'source_type' => 'admin',
        ]);
        FeatureAccess::flush();

        // An active regular (ordered recently) then a brand-new signup (never ordered).
        $regular = Customer::create(['store_id' => $store->id, 'name' => 'Reg', 'phone' => '5'.Str::random(7), 'last_order_at' => now()->subDay()]);
        $fresh = Customer::create(['store_id' => $store->id, 'name' => 'New', 'phone' => '5'.Str::random(7), 'last_order_at' => null]);

        $base = '/api/v1/stores/'.$store->id.'/customers';

        $this->getJson($base, $h)->assertOk()->assertJsonPath('per_page', 20);
        $this->getJson($base.'?per_page=1', $h)->assertOk()->assertJsonPath('per_page', 1);
        $this->getJson($base.'?per_page=9999', $h)->assertOk()->assertJsonPath('per_page', 100);

        // Default (recent-activity) sort puts the regular first; the fresh signup sinks.
        $recentIds = collect($this->getJson($base, $h)->json('data'))->pluck('id')->all();
        $this->assertSame($regular->id, $recentIds[0]);

        // sort=created puts the newest-registered customer on top.
        $createdIds = collect($this->getJson($base.'?sort=created', $h)->json('data'))->pluck('id')->all();
        $this->assertSame($fresh->id, $createdIds[0], 'sort=created must surface the newest customer for the trigger');
    }

    /** F0-4: the reservations polling source is newest-created first and capped. */
    public function test_reservations_list_is_newest_created_first_and_capped(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $h = $this->auth($owner);
        $store = $this->storeFixture($owner);

        $first = Reservation::create(['store_id' => $store->id, 'name' => 'A', 'party_size' => 2, 'reserved_at' => now()->addDay(), 'status' => 'pending']);
        $second = Reservation::create(['store_id' => $store->id, 'name' => 'B', 'party_size' => 4, 'reserved_at' => now()->addDays(2), 'status' => 'confirmed']);

        $res = $this->getJson('/api/v1/stores/'.$store->id.'/reservations', $h)->assertOk();
        $res->assertJsonPath('per_page', 20);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$second->id, $first->id], $ids, 'reservations must be newest-created first');

        // per_page cap holds, and the status filter narrows the list.
        $this->getJson('/api/v1/stores/'.$store->id.'/reservations?per_page=9999', $h)
            ->assertOk()->assertJsonPath('per_page', 100);
        $confirmed = $this->getJson('/api/v1/stores/'.$store->id.'/reservations?status=confirmed', $h)->assertOk();
        $this->assertSame([$second->id], collect($confirmed->json('data'))->pluck('id')->all());
    }
}
