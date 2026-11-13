<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\LiveUpdates;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Broadcasting\LiveUpdates;
use Juwri\Core\Events\Live\KdsTicketChanged;
use Juwri\Core\Events\Live\PosOrdersChanged;
use Juwri\Core\Events\Live\StoreOrderChanged;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Phase 2 part 2 (Reverb, ROADMAP-LATER item 17): who may listen to store.{id}.orders / .kds / .pos, that the whole
 * thing is off by default, and that the events carry ids + status only.
 */
class LiveChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** Switch live updates on for this test (the channels are registered on the default driver, so re-register). */
    private function turnOn(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'reverb.public.host' => 'ws.example.test',
        ]);
        require base_path('routes/channels.php');
    }

    /** @return array{0: User, 1: Store} */
    private function owned(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function staff(User $owner, Store $store, string $role, ?array $permissions = null): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => $role, 'active' => 1, 'permissions' => $permissions,
        ]);
    }

    private function channel(Store $store, string $suffix): string
    {
        return 'private-store.'.$store->id.'.'.$suffix;
    }

    // ── off by default ────────────────────────────────────────────────────────

    public function test_everything_is_off_by_default(): void
    {
        $this->assertFalse(LiveUpdates::enabled());
        $this->assertNull(LiveUpdates::clientConfig());
        [$owner, $store] = $this->owned();

        // the auth endpoint does not exist while off
        $this->actingAs($owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($store, 'orders')])
            ->assertNotFound();

        Event::fake();
        LiveUpdates::orderChanged(1, $store->id, 'new', true);
        Event::assertNothingDispatched();
    }

    public function test_the_log_and_null_connections_count_as_off(): void
    {
        foreach (['log', 'null'] as $driver) {
            config(['broadcasting.default' => $driver, 'broadcasting.connections.reverb.key' => 'k']);
            $this->assertFalse(LiveUpdates::enabled(), $driver);
        }
    }

    public function test_pages_ship_no_live_script_while_off_and_the_script_when_on(): void
    {
        $this->assertSame('', trim(view('partials.live-updates')->render()));

        $this->turnOn();
        $html = view('partials.live-updates')->render();
        $this->assertStringContainsString('JuwriLiveConfig', $html);
        $this->assertStringContainsString('live-updates.js', $html);
        $this->assertStringNotContainsString('test-secret', $html); // the key is public, the secret never leaves the server
    }

    // ── owners ────────────────────────────────────────────────────────────────

    public function test_owner_may_listen_to_each_channel_of_their_own_store(): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();

        foreach (['orders', 'kds', 'pos'] as $suffix) {
            $this->actingAs($owner)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $this->channel($store, $suffix)])
                ->assertOk()->assertJsonStructure(['auth']);
        }
    }

    public function test_user_of_store_a_is_refused_store_bs_channels(): void
    {
        $this->turnOn();
        [$ownerA] = $this->owned();
        [, $storeB] = $this->owned();

        foreach (['orders', 'kds', 'pos'] as $suffix) {
            $this->actingAs($ownerA)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $this->channel($storeB, $suffix)])
                ->assertForbidden();
        }
    }

    public function test_guests_are_refused(): void
    {
        $this->turnOn();
        [, $store] = $this->owned();

        foreach (['orders', 'kds', 'pos'] as $suffix) {
            $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $this->channel($store, $suffix)])
                ->assertUnauthorized();
        }
    }

    public function test_an_unknown_screen_is_refused(): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();

        $this->actingAs($owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-store.'.$store->id.'.billing'])->assertForbidden();
    }

    // ── staff ─────────────────────────────────────────────────────────────────

    /** Staff sign in on both guards with the staff_session_id marker (see PosController::context()). */
    private function asStaff(StaffMember $staff, User $owner)
    {
        return $this->actingAs($owner, 'web')->actingAs($staff, 'staff')->withSession(['staff_session_id' => $staff->id]);
    }

    /** @return array<string, array{0: string, 1: list<bool>}> role => [orders, kds, pos] */
    public static function roleMatrix(): array
    {
        return [
            'cashier' => ['cashier', [true, true, true]],
            'manager' => ['manager', [true, true, true]],
            'kitchen' => ['kitchen', [false, true, false]],
            'waiter' => ['waiter', [true, false, true]],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_staff_get_exactly_the_channels_their_permissions_allow(string $role, array $expected): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $staff = $this->staff($owner, $store, $role);

        foreach (['orders', 'kds', 'pos'] as $i => $suffix) {
            $res = $this->asStaff($staff, $owner)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $this->channel($store, $suffix)]);
            $expected[$i] ? $res->assertOk() : $res->assertForbidden();
        }
    }

    public function test_staff_without_the_kds_permission_are_refused_the_kds_channel(): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        // a cashier normally has kds; the per-staff override takes it away
        $staff = $this->staff($owner, $store, 'cashier', ['kds' => false]);

        $this->asStaff($staff, $owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($store, 'kds')])->assertForbidden();
        $this->asStaff($staff, $owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($store, 'orders')])->assertOk();
    }

    public function test_staff_of_store_a_are_refused_store_b_even_under_the_same_owner(): void
    {
        $this->turnOn();
        [$owner, $storeA] = $this->owned();
        $storeB = Store::create([
            'user_id' => $owner->id, 'slug' => 'y-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R2', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = $this->staff($owner, $storeA, 'manager');

        foreach (['orders', 'kds', 'pos'] as $suffix) {
            $this->asStaff($staff, $owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($storeB, $suffix)])->assertForbidden();
            $this->asStaff($staff, $owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($storeA, $suffix)])->assertOk();
        }
    }

    public function test_a_staff_only_login_is_checked_the_same_way(): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $kitchen = $this->staff($owner, $store, 'kitchen');

        $this->actingAs($kitchen, 'staff')->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($store, 'kds')])->assertOk();
        $this->actingAs($kitchen, 'staff')->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($store, 'pos')])->assertForbidden();
    }

    public function test_an_inactive_staff_member_is_refused(): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $staff = $this->staff($owner, $store, 'manager');
        $staff->forceFill(['active' => 0])->save();

        $this->asStaff($staff, $owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $this->channel($store, 'orders')])->assertForbidden();
    }

    // ── tenancy ───────────────────────────────────────────────────────────────

    public function test_channel_authorization_runs_inside_the_tenant_context(): void
    {
        $this->turnOn();
        [$owner] = $this->owned();
        $seen = null;
        Broadcast::channel('probe.{id}', function ($user, $id) use (&$seen) {
            $seen = TenantContext::current()->active();

            return true;
        }, ['guards' => ['web', 'staff']]);

        $this->actingAs($owner)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-probe.1'])->assertOk();

        $this->assertTrue($seen, 'the broadcast auth route must open the TenantContext like every other owner-facing route');
    }

    // ── events ────────────────────────────────────────────────────────────────

    public function test_events_carry_ids_and_status_only_on_one_private_channel_each(): void
    {
        $orders = new StoreOrderChanged(7, 42, 'preparing', true);
        $kds = new KdsTicketChanged(7, 42, 'preparing');
        $pos = new PosOrdersChanged(7, 42, 'preparing');

        $this->assertSame(['order_id' => 42, 'status' => 'preparing', 'placed' => true], $orders->broadcastWith());
        $this->assertSame(['order_id' => 42, 'status' => 'preparing'], $kds->broadcastWith());
        $this->assertSame(['order_id' => 42, 'status' => 'preparing'], $pos->broadcastWith());

        $this->assertSame(['private-store.7.orders'], array_map(fn ($c) => $c->name, $orders->broadcastOn()));
        $this->assertSame(['private-store.7.kds'], array_map(fn ($c) => $c->name, $kds->broadcastOn()));
        $this->assertSame(['private-store.7.pos'], array_map(fn ($c) => $c->name, $pos->broadcastOn()));
    }

    public function test_placing_and_changing_an_order_fans_out_when_on_and_never_leaks_customer_data(): void
    {
        $this->turnOn();
        [, $store] = $this->owned();
        Event::fake([StoreOrderChanged::class, KdsTicketChanged::class, PosOrdersChanged::class]);

        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'customer_name' => 'Zorro Private',
            'phone_number' => '555-77', 'status' => 'new', 'seen' => 0, 'created_at' => now(),
        ]);
        Event::assertDispatched(StoreOrderChanged::class, fn ($e) => $e->orderId === $order->id && $e->storeId === $store->id && $e->placed === true);
        Event::assertDispatched(KdsTicketChanged::class, fn ($e) => $e->orderId === $order->id);
        Event::assertDispatched(PosOrdersChanged::class, fn ($e) => $e->orderId === $order->id);

        $order->update(['status' => 'preparing']);
        Event::assertDispatched(StoreOrderChanged::class, fn ($e) => $e->orderId === $order->id && $e->status === 'preparing' && $e->placed === false);

        $order->update(['customer_name' => 'Someone Else']); // not a status change: nothing new
        Event::assertDispatchedTimes(StoreOrderChanged::class, 2);

        $json = json_encode((new StoreOrderChanged($store->id, $order->id, 'new', true))->broadcastWith());
        $this->assertStringNotContainsString('Zorro', $json);
        $this->assertStringNotContainsString('555', $json);
    }

    public function test_an_order_save_never_fails_because_the_event_dispatch_fails(): void
    {
        $this->turnOn();
        [, $store] = $this->owned();
        Queue::fake();
        Event::listen(StoreOrderChanged::class, fn () => throw new \RuntimeException('socket server down'));

        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'seen' => 0, 'created_at' => now()]);

        $this->assertNotNull($order->id);
    }
}
