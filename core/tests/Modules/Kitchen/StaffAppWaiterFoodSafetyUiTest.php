<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Kitchen\Models\FoodSafetyCheck;
use Tests\TestCase;

/**
 * UI-W / UI-FS: the two staff-PWA screens built on top of the already-complete
 * `waiter_mode` and `food_safety` backends.
 *
 * The PWA is a single shell (`/staff-app`) with view chips, so "the screen
 * renders" means the shell ships that chip + its panel, and "refused" means the
 * chip is absent AND the endpoints the panel calls answer 403 — exactly the
 * gates `waiterGuard()` / the food-safety handlers already enforce.
 */
class StaffAppWaiterFoodSafetyUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        FeatureAccess::flush();
    }

    /** plan_gated=false → kill-switch only, so an entitled-by-default store. */
    private function featureOn(string $key): void
    {
        Feature::updateOrCreate(['key' => $key], ['label' => $key, 'plan_gated' => false]);
        app()->forgetInstance('features.plan_gated');
        config(['settings.admin_allow_'.$key => 1]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** Kill switch OFF — refused for every store regardless of purchase. */
    private function featureOff(string $key): void
    {
        Feature::updateOrCreate(['key' => $key], ['label' => $key, 'plan_gated' => false]);
        app()->forgetInstance('features.plan_gated');
        config(['settings.admin_allow_'.$key => 0]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** @return array{0:User,1:Store} */
    private function makeRestaurant(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function makeStaff(User $owner, Store $store, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Staff', 'role' => $role,
            'email' => 'staff'.Str::random(6).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);
    }

    // ------------------------------------------------------------- waiter UI

    public function test_waiter_tab_renders_for_permitted_staff_when_feature_on(): void
    {
        $this->featureOn('waiter_mode');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager'); // has `orders`
        $this->assertTrue($staff->hasPermission('orders'));

        $res = $this->actingAs($staff, 'staff')->get('/staff-app');

        $res->assertOk();
        $res->assertSeeHtml('data-v="waiter"');
        $res->assertSeeHtml('id="waiter"');
        $res->assertSeeHtml('/staff-app/waiter/floor');
        // and the endpoints the panel calls really answer
        $this->actingAs($staff, 'staff')->getJson('/staff-app/waiter/floor')->assertOk()->assertJsonStructure(['tables']);
        $this->actingAs($staff, 'staff')->getJson('/staff-app/waiter/calls')->assertOk()->assertJsonStructure(['calls']);
    }

    public function test_waiter_tab_hidden_and_endpoints_refused_without_orders_permission(): void
    {
        $this->featureOn('waiter_mode');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $staff->permissions = ['orders' => false]; // explicit override beats the role default
        $staff->save();
        $this->assertFalse($staff->fresh()->hasPermission('orders'));

        $this->actingAs($staff->fresh(), 'staff')->get('/staff-app')->assertOk()->assertDontSeeHtml('data-v="waiter"');

        $this->actingAs($staff->fresh(), 'staff')->getJson('/staff-app/waiter/floor')->assertForbidden();
    }

    public function test_waiter_tab_hidden_and_endpoints_refused_when_feature_off(): void
    {
        $this->featureOff('waiter_mode');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');

        $this->actingAs($staff, 'staff')->get('/staff-app')->assertOk()->assertDontSeeHtml('data-v="waiter"');

        $this->actingAs($staff, 'staff')->getJson('/staff-app/waiter/floor')->assertForbidden();
        $this->actingAs($staff, 'staff')->getJson('/staff-app/waiter/calls')->assertForbidden();
    }

    /** The Add-items control's payload must satisfy waiterAddItems()'s validator. */
    public function test_waiter_add_items_control_payload_matches_endpoint_contract(): void
    {
        $this->featureOn('waiter_mode');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'dinein', 'table_number' => '4']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$order->id}/items", [
                'items' => [['item_id' => 1, 'quantity' => 2, 'seat' => 3, 'course' => 2]],
                'hold' => true,
            ])
            ->assertStatus(201)->assertJsonStructure(['added']);

        // fire-course control
        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$order->id}/fire", ['course' => 2])
            ->assertOk()->assertJsonStructure(['fired']);

        // split-by-seat control
        $this->actingAs($staff, 'staff')
            ->getJson("/staff-app/waiter/orders/{$order->id}/split-seat")
            ->assertOk()->assertJsonStructure(['seats']);
    }

    // -------------------------------------------------------- food-safety UI

    public function test_food_safety_tab_renders_when_feature_on(): void
    {
        $this->featureOn('food_safety');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');

        $res = $this->actingAs($staff, 'staff')->get('/staff-app');
        $res->assertOk();
        $res->assertSeeHtml('data-v="safety"');
        $res->assertSeeHtml('id="safety"');
        $res->assertSeeHtml('/staff-app/food-safety-incident');

        $this->actingAs($staff, 'staff')->getJson('/staff-app/food-safety')
            ->assertOk()->assertJsonStructure(['checks']);
    }

    public function test_food_safety_tab_hidden_and_endpoints_refused_when_feature_off(): void
    {
        $this->featureOff('food_safety');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');

        $this->actingAs($staff, 'staff')->get('/staff-app')->assertOk()->assertDontSeeHtml('data-v="safety"');

        $this->actingAs($staff, 'staff')->getJson('/staff-app/food-safety')->assertForbidden();
        $this->actingAs($staff, 'staff')->postJson('/staff-app/food-safety-incident', ['note' => 'x'])->assertForbidden();
    }

    /** The Report-incident control's payload must satisfy logFoodSafetyIncident(). */
    public function test_food_safety_incident_control_payload_matches_endpoint_contract(): void
    {
        $this->featureOn('food_safety');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');

        $this->actingAs($staff, 'staff')
            ->postJson('/staff-app/food-safety-incident', ['note' => 'Fridge door left open'])
            ->assertOk()->assertJsonPath('ok', true);
    }

    /** The Log-reading control posts {value,note} to /staff-app/food-safety/{checkId}. */
    public function test_food_safety_log_control_payload_matches_endpoint_contract(): void
    {
        $this->featureOn('food_safety');
        [$owner, $store] = $this->makeRestaurant();
        $staff = $this->makeStaff($owner, $store, 'manager');

        $check = FoodSafetyCheck::create([
            'store_id' => $store->id, 'name' => 'Walk-in fridge', 'kind' => 'temperature',
            'target_min' => 1, 'target_max' => 5, 'active' => true, 'position' => 1,
        ]);

        $this->actingAs($staff, 'staff')
            ->postJson('/staff-app/food-safety/'.$check->id, ['value' => 9.5, 'note' => 'too warm'])
            ->assertOk()->assertJsonPath('breach', true);

        $this->actingAs($staff, 'staff')->getJson('/staff-app/food-safety')
            ->assertOk()->assertJsonCount(0, 'checks'); // logged today → no longer due
    }
}
