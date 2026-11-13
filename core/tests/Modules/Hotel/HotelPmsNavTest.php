<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS lane B (B1) — the hotel PMS nav group.
 *
 * The five booking-engine links live behind lane A's routes/features4/hotel-pms.php.
 * Every one is Route::has()-guarded (via $it()), so pre-merge the block is invisible
 * and the sidebar renders with no dead links. When the routes exist AND the actor
 * holds the mapped permission the link shows; an un-permissioned staff member never
 * sees a link that would 403 on click.
 */
class HotelPmsNavTest extends TestCase
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
        StoreOption::flushOptionMemo();
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');

        return [$owner, $store];
    }

    /** Register lane A's PMS routes as inert closures so Route::has() resolves them. */
    private function registerPmsRoutes(): void
    {
        foreach ([
            'hotel.pms.bookings', 'hotel.pms.calendar', 'hotel.pms.housekeeping',
            'hotel.pms.types.index', 'hotel.pms.services.index',
        ] as $name) {
            Route::get('/_pms/'.Str::random(8), fn () => '')->name($name);
        }
        app('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * Nav item labels keyed by URL path. juwri_nav_model() caches per current route
     * name, so a unique fake route busts the cache each rebuild (NavFeatureGateTest idiom).
     *
     * @return list<string>
     */
    private function navLabels(string $cacheBuster): array
    {
        $route = new IlluminateRoute(['GET'], 'fake/'.$cacheBuster, [])->name('nav.probe.'.$cacheBuster);
        request()->setRouteResolver(fn () => $route);

        $labels = [];
        foreach (juwri_nav_model() as $tab) {
            foreach ($tab['groups'] as [$group, $items]) {
                foreach ($items as $i) {
                    if (! empty($i['label'])) {
                        $labels[] = (string) $i['label'];
                    }
                }
            }
        }

        return $labels;
    }

    public function test_owner_sees_every_pms_link_when_routes_exist(): void
    {
        [$owner, $store] = $this->store();
        $this->registerPmsRoutes();

        $labels = $this->actingAsOwnerAndBuild($owner, 'owner-full');

        foreach ([
            ___('Bookings'), ___('Calendar'), ___('Housekeeping'),
            ___('Room types & rates'), ___('Services & events'),
        ] as $label) {
            $this->assertContains($label, $labels, "owner nav should offer: $label");
        }
    }

    public function test_unpermissioned_staff_never_sees_a_pms_link(): void
    {
        [$owner, $store] = $this->store();
        $this->registerPmsRoutes();

        // A waiter (holds hotel_requests by role) with explicit denies for all three
        // hotel perms — the un-permissioned floor actor the nav must never advertise to.
        $staff = StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'St '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'waiter', 'active' => 1,
            'permissions' => ['hotel_folio' => false, 'hotel_admin' => false, 'hotel_requests' => false],
        ]);

        $route = new IlluminateRoute(['GET'], 'fake/staff', [])->name('nav.probe.staff');
        request()->setRouteResolver(fn () => $route);

        $labels = $this->actingAs($owner, 'web')->actingAs($staff, 'staff')
            ->withSession(['staff_session_id' => $staff->id])
            ->navLabelsForCurrentActor();

        foreach ([___('Bookings'), ___('Calendar'), ___('Housekeeping'), ___('Room types & rates'), ___('Services & events')] as $label) {
            $this->assertNotContains($label, $labels, "un-permissioned staff must not see: $label");
        }
    }

    // -- helpers that keep the auth context alive across the nav rebuild -------------

    /** @return list<string> */
    private function actingAsOwnerAndBuild(User $owner, string $bust): array
    {
        $this->actingAs($owner, 'web');

        return $this->navLabels($bust);
    }

    /** @return list<string> */
    private function navLabelsForCurrentActor(): array
    {
        $labels = [];
        foreach (juwri_nav_model() as $tab) {
            foreach ($tab['groups'] as [$group, $items]) {
                foreach ($items as $i) {
                    if (! empty($i['label'])) {
                        $labels[] = (string) $i['label'];
                    }
                }
            }
        }

        return $labels;
    }
}
