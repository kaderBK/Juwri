<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

class KdsScheduledReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Store $r, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $r->id, 'status' => 'new', 'type' => 'takeaway',
            'customer_name' => 'C', 'created_at' => now(),
        ], $attrs));
    }

    public function test_unreleased_scheduled_order_is_hidden_from_kds_poll(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        $asap = $this->order($r);
        $scheduled = $this->order($r, [
            'scheduled_at' => Carbon::now()->addHours(3),
            'fire_at' => Carbon::now()->addHours(2),
            'released_at' => null,
        ]);

        $ids = collect($this->actingAs($owner)
            ->getJson(route('kds.poll', ['store_id' => $r->id]))
            ->assertOk()->json())->pluck('id')->all();

        $this->assertContains($asap->id, $ids);
        $this->assertNotContains($scheduled->id, $ids);
    }

    public function test_released_scheduled_order_appears_in_kds_poll(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $scheduled = $this->order($r, [
            'scheduled_at' => Carbon::now()->addHours(3),
            'fire_at' => Carbon::now()->subMinute(),
            'released_at' => Carbon::now(),
        ]);

        $ids = collect($this->actingAs($owner)
            ->getJson(route('kds.poll', ['store_id' => $r->id]))
            ->assertOk()->json())->pluck('id')->all();

        $this->assertContains($scheduled->id, $ids);
    }

    public function test_release_due_releases_past_fire_orders_and_is_idempotent(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        $due = $this->order($r, ['scheduled_at' => Carbon::now()->addHour(), 'fire_at' => Carbon::now()->subMinute()]);
        $future = $this->order($r, ['scheduled_at' => Carbon::now()->addHours(5), 'fire_at' => Carbon::now()->addHours(4)]);

        $svc = app(SchedulingService::class);
        $this->assertSame(1, $svc->releaseDue());
        $this->assertNotNull($due->fresh()->released_at);
        $this->assertNull($future->fresh()->released_at);

        // Second run releases nothing (idempotent).
        $this->assertSame(0, $svc->releaseDue());
    }

    public function test_release_endpoint_releases_a_scheduled_order(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $scheduled = $this->order($r, [
            'scheduled_at' => Carbon::now()->addHours(3),
            'fire_at' => Carbon::now()->addHours(2),
        ]);

        $this->actingAs($owner)
            ->postJson(route('kds.release', $scheduled->id))
            ->assertOk()->assertJson(['released' => true]);

        $this->assertNotNull($scheduled->fresh()->released_at);
    }

    public function test_release_endpoint_is_tenant_scoped(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $rB = $this->storeFixture($ownerB);
        $scheduled = $this->order($rB, ['scheduled_at' => Carbon::now()->addHours(3), 'fire_at' => Carbon::now()->addHours(2)]);

        // Owner A cannot release owner B's order.
        $this->actingAs($ownerA)
            ->postJson(route('kds.release', $scheduled->id))
            ->assertNotFound();
        $this->assertNull($scheduled->fresh()->released_at);
    }
}
