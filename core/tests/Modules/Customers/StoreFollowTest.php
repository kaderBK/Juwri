<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreFollow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * theme-pack-blade · F2 Follow / subscribe-to-brand.
 * Covers: follow/unfollow idempotency, the (customer, restaurant) unique
 * constraint, follower_count updates, and the verified-badge gate.
 */
class StoreFollowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:Customer,1:string} customer + raw token */
    private function customer(Store $store, string $name = 'Dana'): array
    {
        $c = Customer::create([
            'store_id' => $store->id, 'name' => $name, 'phone' => UniquePhone::next('5'),
        ]);
        $raw = CustomerToken::issue($c);

        return [$c, $raw];
    }

    private function follow(string $raw, Store $store)
    {
        return $this->postJson('/r/'.$store->id.'/follow', [], ['Authorization' => 'Bearer '.$raw]);
    }

    private function unfollow(string $raw, Store $store)
    {
        return $this->deleteJson('/r/'.$store->id.'/follow', [], ['Authorization' => 'Bearer '.$raw]);
    }

    public function test_follow_requires_a_customer_token(): void
    {
        $store = $this->storeFixture();

        $this->postJson('/r/'.$store->id.'/follow')->assertStatus(401);
    }

    public function test_follow_creates_an_edge_and_reports_count(): void
    {
        $store = $this->storeFixture();
        [$customer, $raw] = $this->customer($store);

        $this->follow($raw, $store)
            ->assertOk()
            ->assertJson(['following' => true, 'follower_count' => 1]);

        $this->assertDatabaseHas('store_follows', [
            'customer_id' => $customer->id, 'store_id' => $store->id,
        ]);
    }

    public function test_follow_is_idempotent_and_respects_unique_constraint(): void
    {
        $store = $this->storeFixture();
        [$customer, $raw] = $this->customer($store);

        $this->follow($raw, $store)->assertOk();
        $this->follow($raw, $store)->assertOk()->assertJson(['follower_count' => 1]);

        // Only one edge — a double-tap never duplicates.
        $this->assertSame(1, StoreFollow::where('customer_id', $customer->id)
            ->where('store_id', $store->id)->count());
    }

    public function test_unique_constraint_blocks_duplicate_inserts(): void
    {
        $store = $this->storeFixture();
        [$customer] = $this->customer($store);

        StoreFollow::create(['customer_id' => $customer->id, 'store_id' => $store->id]);

        $this->expectException(QueryException::class);
        StoreFollow::create(['customer_id' => $customer->id, 'store_id' => $store->id]);
    }

    public function test_unfollow_is_idempotent_and_updates_count(): void
    {
        $store = $this->storeFixture();
        [$customer, $raw] = $this->customer($store);

        $this->follow($raw, $store)->assertOk();
        $this->unfollow($raw, $store)->assertOk()->assertJson(['following' => false, 'follower_count' => 0]);
        // Unfollowing again is a harmless no-op.
        $this->unfollow($raw, $store)->assertOk()->assertJson(['following' => false, 'follower_count' => 0]);

        $this->assertDatabaseMissing('store_follows', [
            'customer_id' => $customer->id, 'store_id' => $store->id,
        ]);
    }

    public function test_follower_count_and_is_following_accessors(): void
    {
        $store = $this->storeFixture();
        [$c1, $raw1] = $this->customer($store, 'A');
        [$c2] = $this->customer($store, 'B');

        $this->follow($raw1, $store)->assertOk();
        StoreFollow::create(['customer_id' => $c2->id, 'store_id' => $store->id]);

        $store->refresh();
        $this->assertSame(2, $store->follower_count);
        $this->assertTrue($store->isFollowedBy($c1));
        $this->assertTrue($store->isFollowedBy($c2->id));
        $this->assertFalse($store->isFollowedBy(null)); // logged-out
    }

    public function test_verified_badge_only_when_is_verified_set(): void
    {
        $store = $this->storeFixture();

        // Default: not verified.
        $this->assertFalse($store->isVerified());
        $this->assertFalse((bool) $store->is_verified);

        $store->is_verified = true;
        $store->save();
        $store->refresh();

        $this->assertTrue($store->isVerified());
        $this->assertTrue($store->is_verified);
    }

    // ---- B6 · notification opt-out via the "Following" sheet ----

    public function test_plain_follow_defaults_notify_to_true(): void
    {
        $store = $this->storeFixture();
        [$c, $raw] = $this->customer($store);

        $this->follow($raw, $store)->assertOk()->assertJson(['following' => true, 'notify' => true]);

        $this->assertTrue((bool) StoreFollow::where('customer_id', $c->id)
            ->where('store_id', $store->id)->value('notify'));
    }

    public function test_notify_opt_out_and_back_in_via_the_follow_endpoint(): void
    {
        $store = $this->storeFixture();
        [$c, $raw] = $this->customer($store);
        $this->follow($raw, $store)->assertOk();

        // Opt out.
        $this->postJson('/r/'.$store->id.'/follow', ['notify' => 0], ['Authorization' => 'Bearer '.$raw])
            ->assertOk()->assertJson(['following' => true, 'notify' => false]);
        $this->assertFalse((bool) StoreFollow::where('customer_id', $c->id)
            ->where('store_id', $store->id)->value('notify'));

        // Opt back in — and it never toggles the follow edge off.
        $this->postJson('/r/'.$store->id.'/follow', ['notify' => 1], ['Authorization' => 'Bearer '.$raw])
            ->assertOk()->assertJson(['following' => true, 'notify' => true]);
        $this->assertSame(1, StoreFollow::where('customer_id', $c->id)
            ->where('store_id', $store->id)->count());
    }
}
