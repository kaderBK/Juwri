<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P9: the storefront engagement reads the themes used to run inline now live in
 * one memoized service. Values must match the old inline queries and repeat reads
 * must not re-query.
 */
class StorefrontContextTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
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

    public function test_loyalty_program_resolves_and_memoizes(): void
    {
        $store = $this->store();
        LoyaltyProgram::create(['store_id' => $store->id, 'type' => 'stamps', 'active' => true]);

        $ctx = new StorefrontContext;
        $prog = $ctx->loyaltyProgram($store);
        $this->assertNotNull($prog);
        $this->assertTrue((bool) $prog->active, 'the active program is returned');

        // An inactive program is not returned (mirrors the old ->where('active',true)).
        $post2 = $this->store();
        LoyaltyProgram::create(['store_id' => $post2->id, 'type' => 'points', 'active' => false]);
        $this->assertNull($ctx->loyaltyProgram($post2), 'inactive program is skipped');

        // Second read is memoized: no further query.
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $again = $ctx->loyaltyProgram($store);
        $this->assertSame($prog->id, $again->id);
        $this->assertSame(0, $queries, 'repeat read must be memoized');
    }

    public function test_waitlist_and_reservation_match_the_signed_in_diner(): void
    {
        $store = $this->store();
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Di', 'phone' => '+9715550001', 'email' => Str::random(6).'@x.co']);

        WaitlistEntry::create(['store_id' => $store->id, 'name' => 'Di', 'phone' => $customer->phone, 'party_size' => 2, 'status' => 'waiting']);
        Reservation::create(['store_id' => $store->id, 'name' => 'Di', 'phone' => $customer->phone, 'party_size' => 2, 'status' => 'confirmed', 'reserved_at' => now()->addDay(), 'token' => Str::random(10)]);

        $ctx = new StorefrontContext;
        $this->assertNotNull($ctx->activeWaitlist($store, $customer), 'live waiting entry found');
        $this->assertNotNull($ctx->upcomingReservation($store, $customer), 'upcoming reservation found');

        // A guest (no customer) gets null and issues no query.
        $this->assertNull($ctx->activeWaitlist($store, null));
        $this->assertNull($ctx->upcomingReservation($store, null));
    }

    public function test_customer_api_token_is_minted_for_a_signed_in_diner(): void
    {
        $store = $this->store();
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Di', 'phone' => '+9715550002', 'email' => Str::random(6).'@x.co']);

        $ctx = new StorefrontContext;
        $token = $ctx->customerApiToken($customer, 'crave');
        $this->assertNotEmpty($token);
        $this->assertNull($ctx->customerApiToken(null, 'crave'), 'guests get no token');
    }
}
