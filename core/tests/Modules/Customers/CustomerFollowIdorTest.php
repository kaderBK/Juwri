<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreFollow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Tests\TestCase;

/**
 * P6-153: `customer.follow` / `customer.unfollow` (POST|DELETE r/{store}/follow) were "unresolved" in the
 * P6-59 IDOR sweep (only a store param, no record id). Authorization rule, proven here:
 *  - the follower is ALWAYS the bearer token's customer (never a body field);
 *  - a customer account belongs to one store, so it may follow/unfollow only that store — any other store,
 *    by id or by slug, is a 404 and writes nothing;
 *  - an unfollow deletes only the caller's own edge, never another customer's edge on the same store.
 */
class CustomerFollowIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(): Store
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

    /** @return array{0:Customer,1:array<string,string>} */
    private function customer(Store $store): array
    {
        $c = Customer::create(['store_id' => $store->id, 'name' => 'C'.Str::random(4), 'phone' => '5'.random_int(100000, 999999)]);

        return [$c, ['Authorization' => 'Bearer '.CustomerToken::issue($c)]];
    }

    public function test_a_customer_cannot_follow_another_store_by_id_or_slug(): void
    {
        $a = $this->store();
        $b = $this->store();
        [$attacker, $auth] = $this->customer($a);

        $this->postJson('/r/'.$b->id.'/follow', [], $auth)->assertNotFound();
        $this->postJson('/r/'.$b->slug.'/follow', [], $auth)->assertNotFound();

        $this->assertSame(0, StoreFollow::where('store_id', $b->id)->count());
        $this->assertSame(0, StoreFollow::where('customer_id', $attacker->id)->count());
    }

    public function test_a_customer_cannot_unfollow_or_toggle_on_another_store(): void
    {
        $a = $this->store();
        $b = $this->store();
        [, $auth] = $this->customer($a);
        [$victim] = $this->customer($b);
        StoreFollow::create(['customer_id' => $victim->id, 'store_id' => $b->id]);

        $this->deleteJson('/r/'.$b->id.'/follow', [], $auth)->assertNotFound();
        $this->postJson('/r/'.$b->id.'/follow', ['notify' => 0], $auth)->assertNotFound();

        $edge = StoreFollow::where('customer_id', $victim->id)->where('store_id', $b->id)->first();
        $this->assertNotNull($edge, 'store B customer edge must survive');
        $this->assertNotFalse((bool) ($edge->notify ?? true));
    }

    public function test_body_customer_id_is_ignored_and_unfollow_touches_only_the_callers_edge(): void
    {
        $a = $this->store();
        [$me, $auth] = $this->customer($a);
        [$sibling] = $this->customer($a);
        StoreFollow::create(['customer_id' => $sibling->id, 'store_id' => $a->id]);

        // A forged customer_id in the body is ignored: the edge is created for the token's customer.
        $this->postJson('/r/'.$a->id.'/follow', ['customer_id' => $sibling->id, 'notify' => 0], $auth)->assertOk();
        $this->assertTrue(StoreFollow::where('customer_id', $me->id)->where('store_id', $a->id)->exists());
        $this->assertTrue((bool) (StoreFollow::where('customer_id', $sibling->id)->first()->notify ?? true));

        $this->deleteJson('/r/'.$a->id.'/follow', ['customer_id' => $sibling->id], $auth)->assertOk()->assertJson(['following' => false]);
        $this->assertFalse(StoreFollow::where('customer_id', $me->id)->exists());
        $this->assertTrue(StoreFollow::where('customer_id', $sibling->id)->where('store_id', $a->id)->exists());
    }

    public function test_follow_needs_a_customer_token_even_for_the_store_owner(): void
    {
        $a = $this->store();
        $this->postJson('/r/'.$a->id.'/follow')->assertUnauthorized();
        $this->deleteJson('/r/'.$a->id.'/follow')->assertUnauthorized();
        $this->actingAs(User::find($a->user_id))->postJson('/r/'.$a->id.'/follow')->assertUnauthorized();
        $this->assertSame(0, StoreFollow::count());
    }
}
