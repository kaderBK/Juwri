<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Driver;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\DeliveryService;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Integrations\Models\ApiToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-354: the owner API PUT /stores/{id}/orders/{orderId}/delivery-status
 * now guards the delivery lifecycle instead of setting any status on any order.
 *
 * Each refusal case pins BOTH the HTTP status code/error and that the row's
 * delivery_status is untouched in the DB, so a future regression that answers
 * 422 but still writes (or writes then errors) fails here.
 */
class DeliveryStatusApiGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        // The route sits behind `feature:driver_app`, which (like the web
        // dispatch shell) is plan-gated. The fixture owner must be entitled for
        // any request to reach the controller; the baseline mirrors
        // FeaturePlatformFix1DriverApiTest.
        Feature::where('key', 'driver_app')->update(['plan_gated' => true]);
        FeatureRegistry::clearCache(); // mass update fires no model event
        $this->killSwitch(true);
        $this->forgetGatedMemo();
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_driver_app' => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
    }

    /** The plan_gated set is memoised per request; a test spans several. */
    private function forgetGatedMemo(): void
    {
        app()->forgetInstance('features.plan_gated');
    }

    /** @return array{0:User,1:Store} an owner with one restaurant, on a bare plan. */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);
        $user = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function token(User $user): string
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $user->id, 'name' => 't',
            'token' => hash('sha256', $raw), 'abilities' => ['read', 'write'],
        ]);

        return $raw;
    }

    private function entitle(User $user): void
    {
        EntitlementWriter::grant($user, 'driver_app', 'admin', null, '1', null, 'test-fixture');
    }

    private function driver(User $owner, Store $store): Driver
    {
        return Driver::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'D'.Str::random(4),
            'token' => hash('sha256', Str::random(40)), 'active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function order(Store $store, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'pending',
            'created_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function putStatus(Store $store, Order $order, string $status, string $token, array $extra = []): TestResponse
    {
        return $this->putJson(
            '/api/v1/stores/'.$store->id.'/orders/'.$order->id.'/delivery-status',
            array_merge(['delivery_status' => $status], $extra),
            ['Authorization' => 'Bearer '.$token]
        );
    }

    // ------------------------------------------------------------- guard cases

    public function test_dine_in_order_is_refused_with_not_a_delivery_order(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $order = $this->order($store, ['type' => 'on-table']);

        $this->putStatus($store, $order, 'assigned', $token)
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_a_delivery_order');

        $this->assertNull($order->fresh()->delivery_status);
    }

    public function test_assigned_without_a_driver_is_refused_with_driver_required(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $order = $this->order($store, ['delivery_status' => 'pending']);

        $this->putStatus($store, $order, 'assigned', $token)
            ->assertStatus(422)
            ->assertJsonPath('error', 'driver_required');

        $this->assertSame('pending', $order->fresh()->delivery_status);
    }

    public function test_backward_move_is_refused_with_backward_move(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $driver = $this->driver($owner, $store);
        $order = $this->order($store, ['delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);

        $this->putStatus($store, $order, 'picked_up', $token)
            ->assertStatus(422)
            ->assertJsonPath('error', 'backward_move');

        $this->assertSame('out_for_delivery', $order->fresh()->delivery_status);
    }

    public function test_moving_out_of_delivered_is_refused_with_delivery_finished(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $order = $this->order($store, ['delivery_status' => 'delivered']);

        $this->putStatus($store, $order, 'failed', $token)
            ->assertStatus(422)
            ->assertJsonPath('error', 'delivery_finished');

        $this->assertSame('delivered', $order->fresh()->delivery_status);
    }

    public function test_pod_otp_requires_proof_of_delivery_unless_forced(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        StoreOption::updateStoreOption($store->id, 'delivery_pod_mode', 'otp');
        $driver = $this->driver($owner, $store);
        $order = $this->order($store, ['delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);

        $this->putStatus($store, $order, 'delivered', $token)
            ->assertStatus(422)
            ->assertJsonPath('error', 'proof_of_delivery_required');
        $this->assertSame('out_for_delivery', $order->fresh()->delivery_status);

        $this->putStatus($store, $order, 'delivered', $token, ['force' => true])->assertOk();
        $this->assertSame('delivered', $order->fresh()->delivery_status);

        $this->assertTrue(
            ActivityLog::where('action', 'order.delivery_pod_override')
                ->where('subject_id', $order->id)
                ->where('store_id', $store->id)
                ->exists(),
            'the forced override must be written to the activity log'
        );
    }

    public function test_pod_none_delivery_completes(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $driver = $this->driver($owner, $store);
        $order = $this->order($store, ['delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);

        $this->putStatus($store, $order, 'delivered', $token)->assertOk();

        $this->assertSame('delivered', $order->fresh()->delivery_status);
    }

    public function test_another_owners_store_order_is_not_found(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);

        [$otherOwner, $otherPost] = $this->owner();
        $otherOrder = $this->order($otherPost, ['delivery_status' => 'out_for_delivery']);

        $this->putStatus($otherPost, $otherOrder, 'delivered', $token)->assertStatus(404);

        $this->assertSame('out_for_delivery', $otherOrder->fresh()->delivery_status);
    }

    public function test_a_stale_model_cannot_move_a_terminal_order(): void
    {
        [$owner, $store] = $this->owner();
        $driver = $this->driver($owner, $store);
        $order = $this->order($store, ['delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);

        Bus::fake();

        // A concurrent writer reaches the terminal state first; the in-memory
        // model still reads out_for_delivery, exactly the stale-read window.
        Order::whereKey($order->id)->update(['delivery_status' => 'failed']);

        $result = DeliveryService::setStatus($order, 'delivered');

        $this->assertSame('failed', (string) $result->delivery_status, 'the returned model reflects the terminal row');
        $this->assertSame('failed', $order->fresh()->delivery_status, 'the DB row stays failed');
        $this->assertNull($order->fresh()->delivered_at, 'no delivered timestamp is stamped');

        Bus::assertNotDispatched(DeliverWebhookJob::class);
    }

    public function test_delivery_completes_through_the_api(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $driver = $this->driver($owner, $store);
        $order = $this->order($store, ['delivery_status' => 'out_for_delivery', 'driver_id' => $driver->id]);

        $this->putStatus($store, $order, 'delivered', $token)
            ->assertOk()
            ->assertJsonPath('delivery_status', 'delivered');

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->delivery_status);
        $this->assertNotNull($fresh->delivered_at);
    }
}
