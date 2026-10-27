<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * BL-036 A2a: the staff permission model. PERMISSION_CATALOG lists every grantable key with
 * the route-name prefixes it opens; billing, staff, settings, integrations, domains and backups
 * stay owner-only; integration credentials under a grantable prefix stay closed; the sidebar
 * store/branch switcher (dashboard.context) no longer 403s staff.
 */
class StaffPermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** Route-name families that are never grantable (owner-only). */
    private const OWNER_ONLY = ['staff.', 'settings', 'backup.', 'integrations.', 'stores.domains.', 'transfer.', 'user.plans', 'api.', 'branches.', 'passkeys.', '2fa.', 'referral.', 'stores.whatsappSettings'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function permFor(string $route): string|bool|null
    {
        $m = new \ReflectionMethod(EnsureStaffDashboardPermission::class, 'permissionForRoute');

        return $m->invoke(new EnsureStaffDashboardPermission, $route);
    }

    /** @return list<string> names of the routes behind the staff dashboard fence */
    private function staffRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = $route->getName();
            if ($name && in_array('staff.dashboard', array_filter($route->middleware(), 'is_string'), true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @return array{0: User, 1: Store} */
    private function ownerStore(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    private function staff(Store $store, string $role = 'manager', array $attrs = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'S '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@t.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'active' => 1,
        ] + $attrs);
    }

    private function asStaff(User $owner, StaffMember $staff): self
    {
        // web last: the default guard stays `web` (the owner), as StaffLoginController leaves it.
        return $this->actingAs($staff, 'staff')->actingAs($owner, 'web')->withSession(['staff_session_id' => $staff->id]);
    }

    public function test_the_validated_keys_are_exactly_the_catalog(): void
    {
        $keys = StaffMember::PERMISSION_KEYS;
        $catalog = StaffMember::grantablePermissions();
        sort($keys);
        sort($catalog);
        $this->assertSame($catalog, $keys);

        // Every role default is a grantable key (an owner can switch each one off).
        foreach (StaffMember::ROLE_PERMISSIONS as $role => $perms) {
            $this->assertSame([], array_values(array_diff($perms, $catalog)), $role);
        }
    }

    public function test_granted_areas_open_their_pages_and_owner_pages_stay_closed(): void
    {
        $this->assertSame('reservations', $this->permFor('reservations.index'));
        $this->assertSame('reservations', $this->permFor('floor-map.index'));
        $this->assertSame('team', $this->permFor('stores.rota.index'));
        $this->assertSame('analytics', $this->permFor('reports.index'));
        $this->assertSame('finance', $this->permFor('stores.books.index'));
        $this->assertSame('marketing', $this->permFor('stores.whatsappInbox'));
        $this->assertSame('design', $this->permFor('qr.designer'));
        $this->assertSame('coupons', $this->permFor('stores.coupons.index'));
        $this->assertNull($this->permFor('dashboard.context'));

        foreach (['staff.index', 'settings', 'backup.index', 'integrations.index', 'stores.domains.index', 'transfer.index'] as $owner) {
            $this->assertFalse($this->permFor($owner), $owner);
        }
        $this->assertFalse($this->permFor('stores.whatsappSettings'), 'no prefix without a dot: WhatsApp settings stay owner-only');
        $this->assertFalse($this->permFor('referral.index'), 'the owner referral programme is account-level');
        $this->assertSame('marketing', $this->permFor('stores.whatsappInbox'));
        $this->assertSame('reviews', $this->permFor('dashboard.store-reviews'));
        foreach (['stores.dispatch.couriers.save', 'stores.accounting.connect', 'fiscal.credentials', 'waitlist.rwg-settings', 'reservations.settings',
            'fiscal.zatca.onboard', 'fiscal.zatca.renew', 'fiscal.eta.device', 'reservations.deposit.manual-refund'] as $secret) {
            $this->assertFalse($this->permFor($secret), $secret.' (integration credentials stay owner-only)');
        }
    }

    public function test_every_catalog_prefix_opens_real_staff_pages_and_never_an_owner_only_family(): void
    {
        $routes = $this->staffRoutes();
        $this->assertGreaterThan(500, count($routes));

        foreach (StaffMember::PERMISSION_CATALOG as $group) {
            foreach ($group as $key => $spec) {
                foreach ($spec['routes'] ?? [] as $exact) {
                    $this->assertContains($exact, $routes, "$key: route $exact is not a staff route");
                }
                foreach ($spec['prefixes'] ?? [] as $prefix) {
                    $this->assertStringEndsWith('.', $prefix, "$key: prefix $prefix must end in a dot (use `routes` for exact names)");
                    $this->assertNotEmpty(array_filter($routes, fn ($r) => str_starts_with($r, $prefix)), "$key: prefix $prefix matches no staff route");
                    foreach (self::OWNER_ONLY as $closed) {
                        $this->assertFalse(str_starts_with($prefix, $closed) || str_starts_with($closed, $prefix), "$key: prefix $prefix overlaps owner-only $closed");
                    }
                }
            }
        }
        // No owner-only route is reachable through a grantable permission.
        foreach ($routes as $name) {
            foreach (self::OWNER_ONLY as $closed) {
                if (str_starts_with($name, $closed)) {
                    $this->assertFalse($this->permFor($name), $name);
                }
            }
        }
    }

    public function test_permission_gates_the_page_for_real(): void
    {
        [$owner, $store] = $this->ownerStore();
        $manager = $this->staff($store, 'manager');
        $cashier = $this->staff($store, 'cashier');

        // Manager holds analytics + dispatch by default; a cashier holds neither, nobody holds marketing.
        $this->assertOpens($this->asStaff($owner, $manager)->get(route('reports.index'))->status());
        $this->app['auth']->forgetGuards();
        $this->assertOpens($this->asStaff($owner, $manager)->get(route('drivers.index'))->status());
        $this->app['auth']->forgetGuards();
        $this->asStaff($owner, $cashier)->get(route('reports.index'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->asStaff($owner, $cashier)->get(route('campaigns.index'))->assertForbidden();

        $cashier->forceFill(['permissions' => ['marketing' => true]])->save();
        $this->app['auth']->forgetGuards();
        $this->assertOpens($this->asStaff($owner, $cashier->fresh())->get(route('campaigns.index'))->status());
    }

    private function assertOpens(int $status): void
    {
        $this->assertNotSame(403, $status);
        $this->assertLessThan(500, $status);
    }

    public function test_staff_can_switch_branch_without_403(): void
    {
        [$owner, $store] = $this->ownerStore();
        $downtown = Branch::create(['store_id' => $store->id, 'name' => 'Downtown']);
        $staff = $this->staff($store);

        $res = $this->asStaff($owner, $staff)->from(route('dashboard'))
            ->post(route('dashboard.context'), ['store_id' => $store->id, 'branch_id' => $downtown->id, 'context_action' => 'branch']);

        $this->assertNotSame(403, $res->status());
        $res->assertRedirect()->assertSessionHas('dashboard_branch_id', $downtown->id);
    }

    public function test_staff_cannot_switch_into_another_owners_store(): void
    {
        [$owner, $store] = $this->ownerStore();
        [, $other] = $this->ownerStore();
        $otherBranch = Branch::create(['store_id' => $other->id, 'name' => 'Theirs']);
        $staff = $this->staff($store);

        $this->asStaff($owner, $staff)->post(route('dashboard.context'), ['store_id' => $other->id, 'branch_id' => $otherBranch->id])
            ->assertSessionMissing('dashboard_branch_id');
    }

    public function test_branch_pinned_staff_stays_on_their_branch(): void
    {
        [$owner, $store] = $this->ownerStore();
        $downtown = Branch::create(['store_id' => $store->id, 'name' => 'Downtown']);
        $airport = Branch::create(['store_id' => $store->id, 'name' => 'Airport']);
        $staff = $this->staff($store, 'manager', ['branch_id' => $downtown->id]);

        $this->asStaff($owner, $staff)->post(route('dashboard.context'), ['store_id' => $store->id, 'branch_id' => $airport->id, 'context_action' => 'branch']);

        $this->assertSame($downtown->id, dashboard_current_branch()?->id);
    }

    public function test_saving_keeps_overrides_for_keys_the_sheet_did_not_show_and_refuses_unknown_keys(): void
    {
        [$owner, $store] = $this->ownerStore();
        $staff = $this->staff($store, 'cashier', ['permissions' => ['biolink' => true, 'pos' => false]]);

        $this->actingAs($owner)->put(route('staff.update', $staff->id), [
            'name' => $staff->name, 'role' => 'cashier', 'permissions' => ['finance' => '1', 'orders' => '0'],
        ])->assertRedirect();

        $fresh = $staff->fresh();
        $this->assertTrue($fresh->hasPermission('finance'));
        $this->assertFalse($fresh->hasPermission('orders'));
        $this->assertTrue($fresh->hasPermission('biolink'), 'an override the form did not post is kept');
        $this->assertFalse($fresh->hasPermission('pos'));

        $this->actingAs($owner)->putJson(route('staff.update', $staff->id), [
            'name' => $staff->name, 'role' => 'cashier', 'permissions' => ['superadmin' => '1'],
        ])->assertStatus(422);
    }

    public function test_existing_staff_keep_their_access_when_role_defaults_grow(): void
    {
        [, $store] = $this->ownerStore();
        $manager = $this->staff($store, 'manager');
        $waiter = $this->staff($store, 'waiter', ['permissions' => ['reservations' => true]]);
        $kitchen = $this->staff($store, 'kitchen');
        $cashier = $this->staff($store, 'cashier');

        $migration = require base_path('app/Modules/Team/Database/Migrations/2026_10_27_110000_keep_existing_staff_access_on_new_role_defaults.php');
        $migration->up();

        $m = $manager->fresh();
        foreach (['reservations', 'waiter', 'dispatch', 'catering', 'food_safety', 'inventory'] as $key) {
            $this->assertFalse($m->hasPermission($key), "manager: $key stays off for an existing member");
        }
        $this->assertTrue($m->hasPermission('orders'), 'old defaults are untouched');
        $this->assertTrue($waiter->fresh()->hasPermission('reservations'), 'an owner override wins');
        $this->assertFalse($waiter->fresh()->hasPermission('waiter'));
        $this->assertFalse($kitchen->fresh()->hasPermission('food_safety'));
        $this->assertNull($cashier->fresh()->permissions, 'roles without new defaults are not touched');

        // A member created after the deploy gets the new defaults.
        $this->assertTrue($this->staff($store, 'manager')->hasPermission('inventory'));

        $migration->up(); // idempotent
        $this->assertTrue($waiter->fresh()->hasPermission('reservations'));
    }
}
