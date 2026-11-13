<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\Order;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Jobs\PushPosOrderJob;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeRegistry;
use Tests\Support\FailingPosProvider;
use Tests\Support\FakePosProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B1-P1: POS bridge contract + registry + connections + item map +
 * idempotent push job.
 */
class PosBridgeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1); // P1-04 5b: fixture store ids must be real stores
        PosBridgeRegistry::flush();
        // pos_bridge default is OFF (run-2 feature) — turn it on for push tests.
        config(['settings.admin_allow_pos_bridge' => true]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Store $r): Order
    {
        return Order::create([
            'store_id' => $r->id, 'type' => 'pickup', 'status' => 'new', 'created_at' => now(),
        ]);
    }

    private function fakeProvider(): FakePosProvider
    {
        return new FakePosProvider;
    }

    private function failingProvider(string $error = 'HTTP 500'): PosBridgeProvider
    {
        return new FailingPosProvider($error);
    }

    public function test_failed_push_throws_so_the_queue_retries(): void
    {
        config(['queue.default' => 'database']);
        PosBridgeRegistry::register($this->failingProvider());
        $r = $this->storeFixture();
        $o = $this->order($r);
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $r->id, 'status' => 'active']);

        $this->expectException(\RuntimeException::class);
        new PushPosOrderJob($o->id, 'fake_pos')->handle();
    }

    public function test_failed_push_records_the_error_on_the_ledger(): void
    {
        config(['queue.default' => 'database']);
        PosBridgeRegistry::register($this->failingProvider('HTTP 500'));
        $r = $this->storeFixture();
        $o = $this->order($r);
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $r->id, 'status' => 'active']);

        try {
            new PushPosOrderJob($o->id, 'fake_pos')->handle();
        } catch (\RuntimeException $e) {
            // expected — the queue would retry
        }

        $push = PosOrderPush::where('order_id', $o->id)->first();
        $this->assertSame('HTTP 500', $push->error);
        // attempts() === 1 on a direct handle() call, $tries === 3, so the budget remains.
        $this->assertSame('pending', $push->status);
    }

    public function test_sync_driver_never_throws(): void
    {
        config(['queue.default' => 'sync']);
        PosBridgeRegistry::register($this->failingProvider());
        $r = $this->storeFixture();
        $o = $this->order($r);
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $r->id, 'status' => 'active']);

        new PushPosOrderJob($o->id, 'fake_pos')->handle(); // must not throw

        $this->assertSame('failed', PosOrderPush::where('order_id', $o->id)->first()->status);
    }

    public function test_provider_unavailable_does_not_throw(): void
    {
        config(['queue.default' => 'database']);
        PosBridgeRegistry::flush(); // no provider registered -> get('fake_pos') is null
        $r = $this->storeFixture();
        $o = $this->order($r);
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $r->id, 'status' => 'active']);

        new PushPosOrderJob($o->id, 'fake_pos')->handle(); // must not throw

        $push = PosOrderPush::where('order_id', $o->id)->first();
        $this->assertSame('failed', $push->status);
        $this->assertSame('provider_unavailable', $push->error);
    }

    public function test_registry_registers_and_resolves_a_provider(): void
    {
        $p = $this->fakeProvider();
        PosBridgeRegistry::register($p);
        $this->assertSame($p, PosBridgeRegistry::get('fake_pos'));
        $this->assertContains('fake_pos', PosBridgeRegistry::keys());
    }

    public function test_pos_bridge_is_registered_in_feature_registry_default_off(): void
    {
        $this->assertArrayHasKey('pos_bridge', FeatureRegistry::keyed());
        $this->assertFalse(FeatureRegistry::keyed()['pos_bridge']['default']);
    }

    public function test_item_map_resolves_external_id(): void
    {
        PosItemMap::create(['store_id' => 1, 'provider' => 'fake_pos', 'menu_id' => 55, 'external_id' => 'sq55']);
        $this->assertSame('sq55', PosItemMap::externalIdFor('fake_pos', 55));
        $this->assertNull(PosItemMap::externalIdFor('fake_pos', 999));
    }

    public function test_job_pushes_once_and_records_external_id(): void
    {
        $p = $this->fakeProvider();
        PosBridgeRegistry::register($p);
        $r = $this->storeFixture();
        $o = $this->order($r);
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $r->id, 'status' => 'active']);

        new PushPosOrderJob($o->id, 'fake_pos')->handle();

        $this->assertSame([$o->id], $p->pushed);
        $push = PosOrderPush::where('order_id', $o->id)->where('provider', 'fake_pos')->first();
        $this->assertSame('pushed', $push->status);
        $this->assertSame('EXT-'.$o->id, $push->external_id);
        $this->assertNotNull($r->fresh() && PosBridgeConnection::resolve('fake_pos', $r->id)->last_push_at);
    }

    public function test_job_is_idempotent_on_replay(): void
    {
        $p = $this->fakeProvider();
        PosBridgeRegistry::register($p);
        $r = $this->storeFixture();
        $o = $this->order($r);
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $r->id, 'status' => 'active']);

        new PushPosOrderJob($o->id, 'fake_pos')->handle();
        new PushPosOrderJob($o->id, 'fake_pos')->handle();

        $this->assertCount(1, $p->pushed, 'replay must not double-push');
        $this->assertSame(1, PosOrderPush::where('order_id', $o->id)->count());
    }

    public function test_job_noop_when_kill_switch_off(): void
    {
        config(['settings.admin_allow_pos_bridge' => false]);
        $p = $this->fakeProvider();
        PosBridgeRegistry::register($p);
        $r = $this->storeFixture();
        $o = $this->order($r);
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $r->id, 'status' => 'active']);

        new PushPosOrderJob($o->id, 'fake_pos')->handle();

        $this->assertCount(0, $p->pushed);
        $this->assertSame(0, PosOrderPush::count());
    }

    public function test_job_noop_when_no_connection(): void
    {
        $p = $this->fakeProvider();
        PosBridgeRegistry::register($p);
        $r = $this->storeFixture();
        $o = $this->order($r);
        // No connection for this restaurant.

        new PushPosOrderJob($o->id, 'fake_pos')->handle();

        $this->assertCount(0, $p->pushed);
        $this->assertSame(0, PosOrderPush::count());
    }

    public function test_connection_resolve_is_tenant_scoped(): void
    {
        $a = $this->storeFixture();
        $b = $this->storeFixture();
        PosBridgeConnection::create(['provider' => 'fake_pos', 'store_id' => $a->id, 'status' => 'active']);

        $this->assertNotNull(PosBridgeConnection::resolve('fake_pos', $a->id));
        $this->assertNull(PosBridgeConnection::resolve('fake_pos', $b->id));
    }
}
