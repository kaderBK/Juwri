<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\LiveUpdates;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Broadcasting\LiveUpdates;
use Juwri\Core\Events\Live\KdsTicketChanged;
use Juwri\Core\Events\Live\PosOrdersChanged;
use Juwri\Core\Events\Live\StoreOrderChanged;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-19 (REVERB-GAPS G1, G2, G4): branch channels store.{id}.branch.{bid}.orders / .kds / .pos, cross-store refusal
 * on both channel kinds, the event fan-out to store + branch channel, the exact public client config, and the
 * throttle on the broadcast auth route.
 */
class LiveBranchChannelTest extends TestCase
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
            'broadcasting.connections.reverb.app_id' => '4242',
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

        return [$owner, $this->store($owner)];
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function branch(Store $store): Branch
    {
        return Branch::create(['store_id' => $store->id, 'name' => 'B '.Str::random(4), 'active' => true]);
    }

    private function staff(User $owner, Store $store, string $role, ?Branch $branch = null, ?array $permissions = null): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'branch_id' => $branch?->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => $role, 'active' => 1, 'permissions' => $permissions,
        ]);
    }

    /**
     * Forget the previous actor: signed-in guards and the session (staff_session_id, AuthenticateSession's
     * password_hash_{guard}) live on across requests in a test. invalidate(), not flush(): the array handler still
     * holds the last request's session under the same id, and withSession() would read it back in, so the next staff
     * member would meet the previous one's password_hash_staff and be logged out (401).
     */
    private function fresh(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->invalidate();

        return $this;
    }

    private function asOwner(User $owner): static
    {
        return $this->fresh()->actingAs($owner, 'web');
    }

    /** Staff sign in on both guards with the staff_session_id marker (see PosController::context()). */
    private function asStaff(StaffMember $staff, User $owner): static
    {
        return $this->fresh()->actingAs($owner, 'web')->actingAs($staff, 'staff')->withSession(['staff_session_id' => $staff->id]);
    }

    /** A staff-only guard login (no owner web login, no staff session marker). */
    private function asStaffOnly(StaffMember $staff): static
    {
        return $this->fresh()->actingAs($staff, 'staff');
    }

    private function storeChannel(Store $store, string $suffix): string
    {
        return 'private-store.'.$store->id.'.'.$suffix;
    }

    private function branchChannel(Store $store, Branch $branch, string $suffix): string
    {
        return 'private-store.'.$store->id.'.branch.'.$branch->id.'.'.$suffix;
    }

    private function sign(string $channel)
    {
        return $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    /** @return array<string, array{0: string}> */
    public static function screens(): array
    {
        return ['orders' => ['orders'], 'kds' => ['kds'], 'pos' => ['pos']];
    }

    // ── (a) cross-store ───────────────────────────────────────────────────────

    #[DataProvider('screens')]
    public function test_owner_staff_and_branch_staff_of_store_a_are_refused_store_bs_store_and_branch_channels(string $suffix): void
    {
        $this->turnOn();
        [$ownerA, $storeA] = $this->owned();
        [, $storeB] = $this->owned();
        $branchA = $this->branch($storeA);
        $branchB = $this->branch($storeB);
        $staffA = $this->staff($ownerA, $storeA, 'manager');
        $branchStaffA = $this->staff($ownerA, $storeA, 'manager', $branchA);

        $refused = [
            $this->storeChannel($storeB, $suffix),
            $this->branchChannel($storeB, $branchB, $suffix),
            $this->branchChannel($storeB, $branchA, $suffix), // store B with a branch of A: the pair does not match
        ];
        foreach ($refused as $channel) {
            $this->asOwner($ownerA)->sign($channel)->assertForbidden();
            $this->asStaff($staffA, $ownerA)->sign($channel)->assertForbidden();
            $this->asStaff($branchStaffA, $ownerA)->sign($channel)->assertForbidden();
            $this->asStaffOnly($branchStaffA)->sign($channel)->assertForbidden();
        }
    }

    // ── (b) branch-limited staff ──────────────────────────────────────────────

    #[DataProvider('screens')]
    public function test_branch_staff_get_only_their_own_branch_channel(string $suffix): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $own = $this->branch($store);
        $other = $this->branch($store);
        $staff = $this->staff($owner, $store, 'manager', $own);

        $this->asStaff($staff, $owner)->sign($this->storeChannel($store, $suffix))->assertForbidden();
        $this->asStaff($staff, $owner)->sign($this->branchChannel($store, $other, $suffix))->assertForbidden();
        $this->asStaff($staff, $owner)->sign($this->branchChannel($store, $own, $suffix))->assertOk()->assertJsonStructure(['auth']);
    }

    #[DataProvider('screens')]
    public function test_a_staff_only_login_limited_to_a_branch_is_checked_the_same_way(string $suffix): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $own = $this->branch($store);
        $other = $this->branch($store);
        $staff = $this->staff($owner, $store, 'manager', $own);

        $this->asStaffOnly($staff)->sign($this->storeChannel($store, $suffix))->assertForbidden();
        $this->asStaffOnly($staff)->sign($this->branchChannel($store, $other, $suffix))->assertForbidden();
        $this->asStaffOnly($staff)->sign($this->branchChannel($store, $own, $suffix))->assertOk();
    }

    #[DataProvider('screens')]
    public function test_branch_staff_without_the_screens_permission_are_refused_their_branch_channel(string $suffix): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $own = $this->branch($store);
        $staff = $this->staff($owner, $store, 'manager', $own, [$suffix => false]);

        $this->asStaff($staff, $owner)->sign($this->branchChannel($store, $own, $suffix))->assertForbidden();
    }

    public function test_inactive_branch_staff_are_refused_their_branch_channel(): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $own = $this->branch($store);
        $staff = $this->staff($owner, $store, 'manager', $own);
        $staff->forceFill(['active' => 0])->save();

        $this->asStaff($staff, $owner)->sign($this->branchChannel($store, $own, 'orders'))->assertForbidden();
    }

    // ── (c) owner and unrestricted staff ──────────────────────────────────────

    #[DataProvider('screens')]
    public function test_owner_and_unrestricted_staff_may_listen_to_the_store_and_to_a_branch_of_it(string $suffix): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $branch = $this->branch($store);
        $staff = $this->staff($owner, $store, 'manager');

        foreach ([$this->storeChannel($store, $suffix), $this->branchChannel($store, $branch, $suffix)] as $channel) {
            $this->asOwner($owner)->sign($channel)->assertOk()->assertJsonStructure(['auth']);
            $this->asStaff($staff, $owner)->sign($channel)->assertOk();
            $this->asStaffOnly($staff)->sign($channel)->assertOk();
        }
    }

    #[DataProvider('screens')]
    public function test_a_branch_of_another_store_is_refused_even_to_the_owner_of_the_first_store(string $suffix): void
    {
        $this->turnOn();
        [$ownerA, $storeA] = $this->owned();
        [, $storeB] = $this->owned();
        $foreign = $this->branch($storeB);
        $staffA = $this->staff($ownerA, $storeA, 'manager');

        $this->asOwner($ownerA)->sign($this->branchChannel($storeA, $foreign, $suffix))->assertForbidden();
        $this->asStaff($staffA, $ownerA)->sign($this->branchChannel($storeA, $foreign, $suffix))->assertForbidden();
    }

    public function test_a_branch_of_the_owners_other_store_is_refused_on_the_first_stores_channel(): void
    {
        $this->turnOn();
        [$owner, $storeA] = $this->owned();
        $storeB = $this->store($owner); // the owner may listen to both stores, but the pair (A, branch of B) is wrong
        $branchOfB = $this->branch($storeB);

        $this->asOwner($owner)->sign($this->branchChannel($storeB, $branchOfB, 'orders'))->assertOk();
        $this->asOwner($owner)->sign($this->branchChannel($storeA, $branchOfB, 'orders'))->assertForbidden();
    }

    public function test_an_unknown_branch_or_screen_is_refused(): void
    {
        $this->turnOn();
        [$owner, $store] = $this->owned();
        $branch = $this->branch($store);

        $this->asOwner($owner)->sign('private-store.'.$store->id.'.branch.999999.orders')->assertForbidden();
        $this->asOwner($owner)->sign('private-store.'.$store->id.'.branch.0.orders')->assertForbidden();
        $this->asOwner($owner)->sign('private-store.'.$store->id.'.branch.'.$branch->id.'.billing')->assertForbidden();
    }

    public function test_guests_are_refused_branch_channels(): void
    {
        $this->turnOn();
        [, $store] = $this->owned();
        $branch = $this->branch($store);

        $this->fresh()->sign($this->branchChannel($store, $branch, 'orders'))->assertUnauthorized();
    }

    // ── branchScope() ─────────────────────────────────────────────────────────

    public function test_branch_scope_is_the_branch_of_branch_limited_staff_only(): void
    {
        [$owner, $store] = $this->owned();
        $branch = $this->branch($store);

        $this->asOwner($owner);
        $this->assertNull(LiveUpdates::branchScope(), 'owner');

        $this->asStaff($this->staff($owner, $store, 'manager'), $owner);
        $this->assertNull(LiveUpdates::branchScope(), 'unrestricted staff');

        $this->asStaff($this->staff($owner, $store, 'manager', $branch), $owner);
        $this->assertSame((int) $branch->id, LiveUpdates::branchScope(), 'branch-limited staff session');
    }

    public function test_branch_scope_for_a_staff_only_login(): void
    {
        [$owner, $store] = $this->owned();
        $branch = $this->branch($store);

        $this->asStaffOnly($this->staff($owner, $store, 'kitchen', $branch));
        $this->assertSame((int) $branch->id, LiveUpdates::branchScope());
    }

    // ── (d) event fan-out ─────────────────────────────────────────────────────

    public function test_events_go_to_the_store_channel_and_to_the_branch_channel_when_there_is_a_branch(): void
    {
        $this->assertSame('store.7.branch.3.kds', LiveUpdates::branchChannel(7, 3, 'kds'));

        $names = fn ($event) => array_map(fn ($c) => $c->name, $event->broadcastOn());

        $this->assertSame(['private-store.7.orders', 'private-store.7.branch.3.orders'], $names(new StoreOrderChanged(7, 42, 'new', true, 3)));
        $this->assertSame(['private-store.7.kds', 'private-store.7.branch.3.kds'], $names(new KdsTicketChanged(7, 42, 'new', 3)));
        $this->assertSame(['private-store.7.pos', 'private-store.7.branch.3.pos'], $names(new PosOrdersChanged(7, 42, 'new', 3)));

        foreach ([null, 0] as $none) {
            $this->assertSame(['private-store.7.orders'], $names(new StoreOrderChanged(7, 42, 'new', true, $none)));
            $this->assertSame(['private-store.7.kds'], $names(new KdsTicketChanged(7, 42, 'new', $none)));
            $this->assertSame(['private-store.7.pos'], $names(new PosOrdersChanged(7, 42, 'new', $none)));
        }

        // the branch id only picks the channel; the payload stays ids + status
        $this->assertSame(['order_id', 'status', 'placed'], array_keys((new StoreOrderChanged(7, 42, 'new', true, 3))->broadcastWith()));
        $this->assertSame(['order_id', 'status'], array_keys((new KdsTicketChanged(7, 42, 'new', 3))->broadcastWith()));
        $this->assertSame(['order_id', 'status'], array_keys((new PosOrdersChanged(7, 42, 'new', 3))->broadcastWith()));
    }

    public function test_an_order_with_a_branch_is_announced_on_its_store_and_branch_channels(): void
    {
        $this->turnOn();
        [, $store] = $this->owned();
        $branch = $this->branch($store);
        Event::fake([StoreOrderChanged::class, KdsTicketChanged::class, PosOrdersChanged::class]);

        $withBranch = Order::create([
            'store_id' => $store->id, 'branch_id' => $branch->id, 'type' => 'takeaway', 'customer_name' => 'Zorro Private',
            'phone_number' => '555-77', 'status' => 'new', 'seen' => 0, 'created_at' => now(),
        ]);
        $plain = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'seen' => 0, 'created_at' => now()]);

        $storeName = 'private-store.'.$store->id;
        $branchName = $storeName.'.branch.'.$branch->id;
        $defaultName = $storeName.'.branch.'.(int) Branch::query()->withoutGlobalScopes()->where('store_id', $store->id)->orderBy('id')->value('id');
        $names = fn ($event) => array_map(fn ($c) => $c->name, $event->broadcastOn());

        foreach ([[StoreOrderChanged::class, 'orders'], [KdsTicketChanged::class, 'kds'], [PosOrdersChanged::class, 'pos']] as [$class, $suffix]) {
            Event::assertDispatched($class, fn ($e) => $e->orderId === $withBranch->id
                && $names($e) === [$storeName.'.'.$suffix, $branchName.'.'.$suffix]);
            // a legacy order with no branch belongs to the store's first (default) branch (ScopesToBranch): its staff hear it too
            Event::assertDispatched($class, fn ($e) => $e->orderId === $plain->id && $names($e) === [$storeName.'.'.$suffix, $defaultName.'.'.$suffix]);
        }

        $withBranch->update(['status' => 'preparing']);
        Event::assertDispatched(StoreOrderChanged::class, fn ($e) => $e->orderId === $withBranch->id && $e->status === 'preparing'
            && $e->branchId === (int) $branch->id && $names($e) === [$storeName.'.orders', $branchName.'.orders']);

        Event::assertDispatched(StoreOrderChanged::class, function ($e) use ($withBranch) {
            $json = json_encode($e->broadcastWith());

            return $e->orderId === $withBranch->id && ! str_contains($json, 'Zorro') && ! str_contains($json, '555');
        });
    }

    public function test_a_store_without_branches_announces_on_the_store_channel_only(): void
    {
        [, $store] = $this->owned();
        Branch::query()->withoutGlobalScopes()->where('store_id', $store->id)->delete();
        $names = array_map(fn ($c) => $c->name, (new StoreOrderChanged($store->id, 42, 'new', true, null))->broadcastOn());
        $this->assertSame(['private-store.'.$store->id.'.orders'], $names);
    }

    public function test_a_kitchen_ticket_change_carries_the_branch(): void
    {
        $this->turnOn();
        Event::fake([KdsTicketChanged::class]);

        LiveUpdates::kdsChanged(42, 7, 'preparing', 3);

        Event::assertDispatched(KdsTicketChanged::class, fn ($e) => $e->branchId === 3
            && array_map(fn ($c) => $c->name, $e->broadcastOn()) === ['private-store.7.kds', 'private-store.7.branch.3.kds']);
    }

    // ── (e) client config ─────────────────────────────────────────────────────

    public function test_client_config_has_exactly_the_five_public_keys(): void
    {
        $this->turnOn();
        $config = LiveUpdates::clientConfig();

        $this->assertIsArray($config);
        $this->assertSame(['key', 'host', 'port', 'scheme', 'authUrl'], array_keys($config));
        $this->assertSame('test-key', $config['key']);
        $json = json_encode($config);
        $this->assertStringNotContainsString('test-secret', $json);
        $this->assertStringNotContainsString('4242', $json);
        $this->assertStringNotContainsString('secret', strtolower($json));
        $this->assertStringNotContainsString('app_id', strtolower($json));
    }

    // ── (f) throttle ──────────────────────────────────────────────────────────

    public function test_the_broadcast_auth_route_is_throttled_after_the_login_check(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => $route->uri() === 'broadcasting/auth',
        ));
        $this->assertNotEmpty($routes, 'broadcasting/auth is registered');

        foreach ($routes as $route) {
            $middleware = array_values(array_map('strval', $route->middleware()));
            $throttle = array_keys(array_filter($middleware, fn ($m) => str_starts_with($m, 'throttle:')));
            $auth = array_search('auth:staff,web', $middleware, true);

            $this->assertNotEmpty($throttle, 'broadcasting/auth has a throttle: '.implode(', ', $middleware));
            $this->assertNotFalse($auth);
            $this->assertGreaterThan($auth, $throttle[0], 'the throttle runs after the login check');
        }
    }
}
