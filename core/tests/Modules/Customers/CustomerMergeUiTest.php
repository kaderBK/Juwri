<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS E9 — merge-duplicate-customers owner UI. Wraps CustomerMergeService;
 * never weakens its passworded-row refusal.
 */
class CustomerMergeUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware();
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
    }

    private function store(User $owner): Store
    {
        $t = 'S'.Str::random(5);
        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1']);
    }

    private function customer(Store $store, array $attrs = []): Customer
    {
        return Customer::create(array_merge(['store_id' => $store->id, 'name' => 'C'.Str::random(4)], $attrs));
    }

    public function test_candidates_shows_same_phone_pair(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $keep = $this->customer($store, ['name' => 'Keeper', 'phone' => '5551234567', 'password' => bcrypt('x')]);
        $dupe = $this->customer($store, ['name' => 'Shadow', 'phone' => '555-123-4567']);

        $html = $this->actingAs($owner)->get(route('customers.merge.index', ['store_id' => $store->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Keeper', $html);
        $this->assertStringContainsString('Shadow', $html);
        $this->assertStringContainsString('name="duplicate_id" value="'.$dupe->id.'"', $html);
        $this->assertStringContainsString('name="primary_id" value="'.$keep->id.'"', $html);
    }

    public function test_merge_folds_duplicate_into_primary(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $keep = $this->customer($store, ['phone' => '5551234567', 'password' => bcrypt('x')]);
        $dupe = $this->customer($store, ['phone' => '555 123 4567']); // same normalized, distinct raw
        $order = Order::factory()->create(['store_id' => $store->id, 'customer_id' => $dupe->id]);

        $this->actingAs($owner)->post(route('customers.merge'), [
            'primary_id' => $keep->id, 'duplicate_id' => $dupe->id, 'confirm' => '1',
        ])->assertRedirect();

        $this->assertDatabaseMissing('customers', ['id' => $dupe->id]);
        $this->assertSame($keep->id, (int) $order->fresh()->customer_id);
    }

    public function test_passworded_duplicate_is_refused(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $keep = $this->customer($store, ['phone' => '5551234567', 'password' => bcrypt('x')]);
        $realDupe = $this->customer($store, ['phone' => '555-123-4567', 'password' => bcrypt('y')]);

        $this->actingAs($owner)->from(route('customers.merge.index'))->post(route('customers.merge'), [
            'primary_id' => $keep->id, 'duplicate_id' => $realDupe->id, 'confirm' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        // Both remain — the refusal is never weakened.
        $this->assertDatabaseHas('customers', ['id' => $realDupe->id]);
        $this->assertDatabaseHas('customers', ['id' => $keep->id]);
    }

    public function test_cross_owner_customer_is_404(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $mine = $this->customer($store);

        $foreignStore = $this->store($this->owner());
        $foreign = $this->customer($foreignStore);

        $this->actingAs($owner)->post(route('customers.merge'), [
            'primary_id' => $mine->id, 'duplicate_id' => $foreign->id, 'confirm' => '1',
        ])->assertNotFound();
    }
}
