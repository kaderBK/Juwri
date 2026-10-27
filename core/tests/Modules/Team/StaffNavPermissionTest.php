<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * BL-036 A2b: staff see only what their role and permissions open (sidebar, account menu,
 * POS register), the nav model is never shared between owner and staff, and the owner's
 * Staff page offers one grouped permission sheet per member plus branch pickers.
 */
class StaffNavPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
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

    private function staff(Store $store, string $role, array $attrs = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'S '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@t.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'active' => 1,
        ] + $attrs);
    }

    /** @return list<string> every nav URL for the current actor (a fresh probe route busts the per-route memo) */
    private function navUrls(string $probe): array
    {
        $route = new IlluminateRoute(['GET'], 'fake/'.$probe, [])->name('nav.probe.'.$probe);
        request()->setRouteResolver(fn () => $route);

        $urls = [];
        foreach (juwri_nav_model() as $tab) {
            foreach ($tab['groups'] as [, $items]) {
                foreach ($items as $i) {
                    $urls[] = (string) ($i['url'] ?? '');
                }
            }
        }

        return $urls;
    }

    private function asStaff(User $owner, StaffMember $staff): void
    {
        $this->actingAs($staff, 'staff')->actingAs($owner, 'web');
        session(['staff_session_id' => $staff->id]);
    }

    public function test_cashier_menu_hides_owner_and_unpermitted_pages(): void
    {
        [$owner, $store] = $this->ownerStore();
        $this->asStaff($owner, $this->staff($store, 'cashier'));

        $urls = $this->navUrls('cashier');

        $this->assertContains(route('stores.orders'), $urls);
        $this->assertContains(route('dashboard'), $urls);
        $this->assertNotContains(route('staff.index'), $urls, 'owner-only Staff page');
        $this->assertNotContains(url('settings'), $urls, 'owner-only Settings');
        $this->assertNotContains(route('backup.index'), $urls);
        $this->assertNotContains(route('reports.index'), $urls, 'cashier holds no analytics');
        // POS register: the live link, or the upsell row when the plan lacks POS.
        $this->assertTrue(collect($urls)->contains(fn ($u) => $u === url('pos') || str_contains($u, 'pos_enabled')), 'POS item missing for a cashier');
    }

    public function test_a_revoked_pos_permission_hides_the_register_and_a_granted_area_shows(): void
    {
        [$owner, $store] = $this->ownerStore();
        $this->asStaff($owner, $this->staff($store, 'cashier', ['permissions' => ['pos' => false, 'analytics' => true]]));

        $urls = $this->navUrls('cashier-custom');

        $this->assertFalse(collect($urls)->contains(fn ($u) => $u === url('pos') || str_contains($u, 'pos_enabled')));
        $this->assertContains(route('reports.index'), $urls);
    }

    public function test_owner_and_staff_never_share_a_menu(): void
    {
        [$owner, $store] = $this->ownerStore();
        $staff = $this->staff($store, 'kitchen');
        $route = new IlluminateRoute(['GET'], 'fake/shared', [])->name('nav.probe.shared');
        request()->setRouteResolver(fn () => $route);

        $this->actingAs($owner, 'web');
        $ownerUrls = collect(juwri_nav_model())->flatMap(fn ($t) => collect($t['groups'])->flatMap(fn ($g) => array_column($g[1], 'url')))->all();
        $this->asStaff($owner, $staff);
        $staffUrls = collect(juwri_nav_model())->flatMap(fn ($t) => collect($t['groups'])->flatMap(fn ($g) => array_column($g[1], 'url')))->all();

        $this->assertContains(route('staff.index'), $ownerUrls);
        $this->assertNotContains(route('staff.index'), $staffUrls, 'same request, same route: the staff menu is built on its own');
    }

    public function test_staff_may_open_is_always_true_outside_a_staff_session(): void
    {
        [$owner] = $this->ownerStore();
        $this->actingAs($owner);

        $this->assertTrue(EnsureStaffDashboardPermission::staffMayOpen('staff.index'));
        $this->assertTrue(EnsureStaffDashboardPermission::staffMayOpen('settings'));
    }

    public function test_owner_staff_page_offers_a_grouped_permission_sheet_and_branch_pickers(): void
    {
        [$owner, $store] = $this->ownerStore();
        Branch::create(['store_id' => $store->id, 'name' => 'Downtown']);
        $staff = $this->staff($store, 'cashier');

        $html = (string) $this->actingAs($owner)->get(route('staff.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ui-open="perms-'.$staff->id.'"', $html);
        $this->assertStringContainsString('id="perms-form-'.$staff->id.'"', $html);
        $this->assertStringContainsString('name="permissions[orders]"', $html);
        $this->assertStringContainsString('name="permissions[marketing]"', $html);
        $this->assertStringContainsString('<legend>Service</legend>', $html);
        $this->assertStringNotContainsString('name="permissions[hotel_folio]"', $html, 'hotel keys only when the store runs a hotel');
        $this->assertStringContainsString('id="sf-branch"', $html);
        $this->assertStringContainsString('ui-staff__branch', $html);
        $this->assertStringContainsString('>Downtown<', $html);
    }

    public function test_owner_can_assign_and_clear_a_staff_branch(): void
    {
        [$owner, $store] = $this->ownerStore();
        $downtown = Branch::create(['store_id' => $store->id, 'name' => 'Downtown']);
        $staff = $this->staff($store, 'manager');

        $this->actingAs($owner)->put(route('staff.update', $staff->id), ['name' => $staff->name, 'role' => 'manager', 'branch_id' => $downtown->id])
            ->assertRedirect();
        $this->assertSame($downtown->id, (int) $staff->fresh()->branch_id);

        $this->actingAs($owner)->put(route('staff.update', $staff->id), ['name' => $staff->name, 'role' => 'manager', 'branch_id' => ''])
            ->assertRedirect();
        $this->assertNull($staff->fresh()->branch_id);
    }

    public function test_sheet_save_posts_the_whole_map(): void
    {
        [$owner, $store] = $this->ownerStore();
        $staff = $this->staff($store, 'cashier');
        $posted = array_fill_keys(StaffMember::PERMISSION_KEYS, '0');
        $posted['team'] = '1';

        $this->actingAs($owner)->put(route('staff.update', $staff->id), [
            'name' => $staff->name, 'role' => 'cashier', 'branch_id' => '', 'permissions' => $posted,
        ])->assertRedirect();

        $fresh = $staff->fresh();
        $this->assertTrue($fresh->hasPermission('team'));
        $this->assertFalse($fresh->hasPermission('orders'), 'a switched-off role default is stored as an override');
    }
}
