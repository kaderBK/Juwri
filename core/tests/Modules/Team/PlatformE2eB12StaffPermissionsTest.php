<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\StaffTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PragmaRX\Google2FA\Google2FA;
use Tests\Modules\Platform\PlatformE2eTestCase;
use Tests\Support\TestPlans;

/**
 * Platform E2E · B12 — the staff roles & permissions seam for dashboard
 * access control, verified against the REAL model/middleware:
 *
 *   - Staff are App\Models\StaffMember rows (table `staff_members`:
 *     owner_id, store_id, role, permissions json, active, pin…) — NOT
 *     `User` rows with parent_id/role, and `App\Models\StaffRole` does not
 *     exist (roles are the fixed enum manager|cashier|kitchen|waiter on
 *     StaffMember::ROLE_PERMISSIONS, with a per-staff `permissions` JSON
 *     override — see StaffMember::hasPermission()).
 *   - A staff dashboard session logs the `staff` guard in as the
 *     StaffMember AND the `web` guard in as the owner (see
 *     Auth\StaffLoginController@login), with `session('staff_session_id')`
 *     the marker `is_staff_dashboard()` keys off — mirrors the proven
 *     `asStaff()` idiom in tests/Modules/Platform/StaffRbacHardeningTest.php.
 *   - `App\Http\Middleware\EnsureStaffDashboardPermission` is the real gate:
 *     default-deny for any route not in its ROUTE_PERMISSION_MAP, and
 *     permission-gated per role otherwise, checked via `dashboard_allows()`.
 *
 * Discriminating tests:
 *   - Staff creation via the owner's real StaffController@store
 *   - Real staff login sets BOTH guards + the staff_session marker
 *   - Default-allow (unmapped-to-null) vs default-deny (unmapped/absent) routes
 *   - Role-based permission enforcement + JSON permission-override widening
 *   - A role change by the owner takes effect on the staff's NEXT request
 *   - TOTP 2FA challenge on the staff-app API login (StaffAuthController)
 */
class PlatformE2eB12StaffPermissionsTest extends PlatformE2eTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        View::share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect(),
        ]);

        // EnsureStaffDashboardPermission is deliberately NOT disabled — it is the
        // gate under test (same idiom as StaffRbacHardeningTest). The
        // Installed/PreventRequestForgery bypass every revived owner-dashboard test
        // needs is now inherited from PlatformE2eTestCase::setUp().
    }

    /**
     * Deliberately NOT PlatformE2eTestCase::ownerWithStore() — the staff
     * permission matrix needs a real Plan (`staff_limit` on group_id) and a
     * bare Post::create() with no store_type/store_template, so this is a
     * materially different fixture, not a copy of the canonical one. See
     * PlatformE2eTestCase's class docblock.
     *
     * @return array{0: User, 1: Store}
     */
    private function ownerWithPlanStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'staff_limit' => 99],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    private function staff(Store $store, string $role = 'kitchen', array $extra = []): StaffMember
    {
        return StaffMember::create(array_merge([
            'owner_id' => $store->user_id,
            'store_id' => $store->id,
            'name' => 'Staff '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@t.test',
            'password' => Hash::make('secret123'),
            'role' => $role,
            'active' => 1,
        ], $extra));
    }

    /**
     * Real staff dashboard session: web guard = owner, staff guard = staff,
     * staff_session_id set. Order matters: Testing\TestCase::actingAs() calls
     * Auth::shouldUse($guard), so the LAST actingAs() wins the DEFAULT guard —
     * 'web' must be last so unqualified auth()/auth()->user() calls (used all
     * over the dashboard layout) resolve the OWNER, exactly like production
     * (StaffLoginController logs guard('staff') in first, then guard('web')).
     */
    private function asStaff(User $owner, StaffMember $staff, ?Store $store = null): self
    {
        $session = ['staff_session_id' => $staff->id];
        if ($store) {
            $session['dashboard_store_id'] = $store->id;
        }

        return $this->actingAs($staff, 'staff')->actingAs($owner, 'web')->withSession($session);
    }

    /**
     * A plain owner-only request. Needed after a prior asStaff() call in the
     * same test: the 'staff' guard keeps its cached user and the session keeps
     * staff_session_id across calls within one test (same app/session
     * instance), which would otherwise make is_staff_dashboard() still see a
     * staff session on what should be the owner's own, unrelated request.
     */
    private function asOwner(User $owner): self
    {
        Auth::guard('staff')->logout();

        return $this->actingAs($owner, 'web')->withSession(['staff_session_id' => null]);
    }

    private function unpaidOrder(Store $store): Order
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 20, 'active' => 1]);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending',
            'is_paid' => 0, 'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'status' => 'queued']);

        return $order->fresh();
    }

    /**
     * Test 1: staff creation via the owner's real dashboard endpoint. The store
     * row is App\Models\StaffMember (table staff_members) — there is no
     * App\Models\StaffRole and staff are never Juwri\Core\Models\User rows.
     */
    public function test_staff_creation_creates_a_restaurant_staff_row(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();

        $response = $this->actingAs($owner, 'web')
            ->post(route('staff.store'), [
                'name' => 'Pat Cashier',
                'email' => 'staff@example.com',
                'password' => 'staffpass123',
                'role' => 'cashier',
                'store_id' => $store->id,
            ]);

        $response->assertRedirect();

        $staff = StaffMember::where('email', 'staff@example.com')->first();
        $this->assertNotNull($staff);
        $this->assertSame('cashier', $staff->role);
        $this->assertSame($owner->id, $staff->owner_id);
        $this->assertSame($store->id, $staff->store_id);
        $this->assertTrue((bool) $staff->active);
        $this->assertTrue(Hash::check('staffpass123', $staff->password));

        // The staff-as-User premise from the old test is dead: no User row was created.
        $this->assertNull(User::where('email', 'staff@example.com')->first());
    }

    /**
     * Test 2: the real staff login (Auth\StaffLoginController@login) logs the
     * `staff` guard in as the staff row AND the `web` guard in as the owner,
     * and stamps session('staff_session_id') — the marker is_staff_dashboard()
     * and every dashboard_* helper key off.
     */
    public function test_staff_login_authenticates_both_guards_and_sets_session_marker(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $staff = $this->staff($store, 'waiter', ['password' => Hash::make('correct-horse')]);

        $response = $this->post(route('staff.login.attempt'), [
            'email' => $staff->email,
            'password' => 'correct-horse',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(Auth::guard('staff')->check());
        $this->assertSame($staff->id, Auth::guard('staff')->id());
        $this->assertSame($owner->id, Auth::guard('web')->id(), 'staff login also logs the web guard in AS THE OWNER');
        $this->assertSame($staff->id, session('staff_session_id'));
    }

    /**
     * Test 3: `dashboard` maps to a NULL permission in
     * EnsureStaffDashboardPermission::ROUTE_PERMISSION_MAP — every staff role,
     * even the least-privileged (kitchen), reaches it.
     */
    public function test_dashboard_home_is_allowed_for_the_least_privileged_role(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $cook = $this->staff($store, 'kitchen');

        $this->asStaff($owner, $cook, $store)
            ->get(route('dashboard'))
            ->assertStatus(200);
    }

    /**
     * Test 4: `staff.index` (staff management) has NO entry in
     * ROUTE_PERMISSION_MAP at all, so permissionForRoute() returns false and
     * EnsureStaffDashboardPermission::handle() default-denies it — for EVERY
     * staff role, including 'manager' who holds the widest ROLE_PERMISSIONS
     * set. Staff management is owner-only by design, not just role-gated.
     */
    public function test_unmapped_staff_management_route_is_default_denied_even_for_manager(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $manager = $this->staff($store, 'manager');

        $this->asStaff($owner, $manager, $store)
            ->get(route('staff.index'))
            ->assertForbidden();
    }

    /**
     * Test 5: a role lacking the route's mapped permission is denied.
     * `restaurants.deleteOrder` requires 'orders' (ROUTE_PERMISSION_MAP); the
     * 'kitchen' role's ROLE_PERMISSIONS is only ['kds'] — no 'orders'.
     */
    public function test_role_lacking_the_mapped_permission_is_denied(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $cook = $this->staff($store, 'kitchen');
        $order = $this->unpaidOrder($store);

        $this->asStaff($owner, $cook, $store)
            ->postJson(route('stores.deleteOrder', $order->id))
            ->assertStatus(403);

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    /**
     * Regression: every kds.* route rides the single 'kds' permission via the
     * `kds.` prefix rule in EnsureStaffDashboardPermission::permissionForRoute().
     * Before that rule only kds.index/poll/bump were mapped; kds.scheduled (and
     * release, setMode, item bump/recall, fireCourse, allDay, analytics) were
     * UNMAPPED, so a
     * 'kitchen' cook — whose ROLE_PERMISSIONS is exactly ['kds'] — 403'd on the
     * scheduled/all-day polls, and the KDS scheduled panel then rendered the
     * HttpException error payload into its x-for (undefined :key) and crashed.
     * Assert both formerly-unmapped GET polls now return a 200 JSON array, never
     * an error object.
     */
    public function test_kitchen_role_reaches_all_kds_polls_via_prefix_rule(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $cook = $this->staff($store, 'kitchen');

        foreach (['kds.scheduled', 'kds.allDay'] as $routeName) {
            $res = $this->asStaff($owner, $cook, $store)
                ->getJson(route($routeName, ['store_id' => $store->id]));

            $res->assertStatus(200);
            $this->assertIsArray($res->json(), "$routeName must return a JSON array");
            $this->assertArrayNotHasKey('exception', (array) $res->json(),
                "$routeName must not return an HttpException error payload");
        }
    }

    /**
     * Test 6: a per-staff `permissions` JSON override
     * (StaffMember::hasPermission() checks it BEFORE the role default)
     * grants access beyond the role's default set — the real mechanism the
     * owner dashboard's "Edit staff" permission checkboxes write.
     */
    public function test_permission_override_grants_access_beyond_role_default(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $cook = $this->staff($store, 'kitchen', ['permissions' => ['orders' => true]]);
        $order = $this->unpaidOrder($store);

        $this->assertTrue($cook->hasPermission('orders'), 'override must widen access on the model itself');

        $this->asStaff($owner, $cook, $store)
            ->postJson(route('stores.deleteOrder', $order->id))
            ->assertStatus(200);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    /**
     * Test 7: when the owner widens a staff member's ROLE (StaffController@update,
     * the real endpoint), the change takes effect on the staff's NEXT request —
     * a fresh guard resolution reads the updated row from the database. (Within
     * ONE request/guard instance nothing changes retroactively — that is not
     * what "no re-login required" means in production, where every request
     * re-resolves the authenticated user from scratch.)
     */
    public function test_role_change_by_owner_takes_effect_on_staffs_next_request(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $cook = $this->staff($store, 'kitchen');
        $firstOrder = $this->unpaidOrder($store);

        // Before: kitchen lacks 'orders'.
        $this->asStaff($owner, $cook, $store)
            ->postJson(route('stores.deleteOrder', $firstOrder->id))
            ->assertStatus(403);

        // Owner widens the role via the real update endpoint.
        $this->asOwner($owner)
            ->put(route('staff.update', $cook->id), ['name' => $cook->name, 'role' => 'waiter'])
            ->assertRedirect();

        $this->assertSame('waiter', $cook->fresh()->role);

        // After, on the staff member's NEXT request (fresh guard resolution
        // picks up the updated role from the database) — access is granted.
        $secondOrder = $this->unpaidOrder($store);
        $this->asStaff($owner, $cook->fresh(), $store)
            ->postJson(route('stores.deleteOrder', $secondOrder->id))
            ->assertStatus(200);

        $this->assertDatabaseMissing('orders', ['id' => $secondOrder->id]);
    }

    /**
     * Test 8: TOTP 2FA on the staff-app API login (StaffAuthController@login) —
     * the real 2FA surface for staff (the dashboard web login has none). Once
     * enrolled+confirmed, a login without a code is challenged, and a valid
     * TOTP completes it.
     */
    public function test_totp_two_factor_challenge_required_and_verified_on_staff_api_login(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $staff = $this->staff($store, 'waiter', ['password' => Hash::make('correct-horse')]);

        $enrollment = StaffTwoFactorService::enrollStart($staff);
        $this->assertNotEmpty($enrollment['secret']);
        $this->assertNull($staff->fresh()->two_factor_confirmed_at);

        $otp = (new Google2FA)->getCurrentOtp($enrollment['secret']);
        $codes = StaffTwoFactorService::confirm($staff, $otp);
        $this->assertNotNull($codes, 'a valid current OTP must confirm enrollment');
        $this->assertNotNull($staff->fresh()->two_factor_confirmed_at);

        // Without a code: challenged, not authenticated.
        $this->postJson(route('api.v1.staff.login', $store->id), [
            'email' => $staff->email,
            'password' => 'correct-horse',
        ])
            ->assertStatus(401)
            ->assertJson(['error' => 'two_factor_required']);

        // The code that confirmed enrolment is used up (P4-06: one use per code) — the next one signs in.
        $this->postJson(route('api.v1.staff.login', $store->id), ['email' => $staff->email, 'password' => 'correct-horse', 'code' => $otp])
            ->assertStatus(401);
        $freshOtp = (new Google2FA)->oathTotp($enrollment['secret'], (int) floor(time() / 30) + 1);
        $this->postJson(route('api.v1.staff.login', $store->id), [
            'email' => $staff->email,
            'password' => 'correct-horse',
            'code' => $freshOtp,
        ])->assertStatus(200);
    }

    /** P6-72: the permissions map takes only the known keys; an unknown key is a 422 and nothing is written. */
    public function test_unknown_permission_key_is_rejected_and_known_keys_are_stored_as_flags(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $cook = $this->staff($store, 'kitchen');

        $this->asOwner($owner)
            ->putJson(route('staff.update', $cook->id), ['name' => $cook->name, 'role' => 'kitchen', 'permissions' => ['is_admin' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');
        $this->asOwner($owner)
            ->putJson(route('staff.update', $cook->id), ['name' => $cook->name, 'role' => 'kitchen', 'permissions' => ['orders' => 'all']])
            ->assertStatus(422);
        $this->assertEmpty($cook->fresh()->permissions);

        $this->asOwner($owner)
            ->put(route('staff.update', $cook->id), ['name' => $cook->name, 'role' => 'kitchen', 'permissions' => ['orders' => '1', 'hotel_admin' => '0']])
            ->assertRedirect();
        $this->assertSame(['orders' => true, 'hotel_admin' => false], $cook->fresh()->permissions);
    }

    /** P6-72: a staff session can never grant permissions or add staff — 403 even past the route map. */
    public function test_staff_session_cannot_grant_permissions_even_without_the_route_gate(): void
    {
        [$owner, $store] = $this->ownerWithPlanStore();
        $manager = $this->staff($store, 'manager');

        $this->withoutMiddleware(EnsureStaffDashboardPermission::class);
        $this->asStaff($owner, $manager, $store)
            ->putJson(route('staff.update', $manager->id), ['name' => $manager->name, 'role' => 'manager', 'permissions' => ['biolink' => 1]])
            ->assertStatus(403);
        $this->asStaff($owner, $manager, $store)
            ->postJson(route('staff.store'), ['name' => 'X', 'email' => 'x@t.test', 'password' => 'Secret123!x', 'role' => 'manager', 'store_id' => $store->id])
            ->assertStatus(403);
        $this->assertEmpty($manager->fresh()->permissions);
    }
}
