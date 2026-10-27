<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-30: the owner "Google branches" panel on the reservations page — every branch
 * of the selected store with its Google merchant id, place-id state and eligibility.
 * A store with one eligible branch keeps the historic `store-<storeId>` merchant id;
 * two+ eligible branches get `store-<storeId>-b<branchId>` per branch. Inactive or
 * un-geocoded branches are "Not published". Branch merchant ids are store-scoped:
 * another store's branches never leak onto the panel.
 */
class ReservationsGoogleBranchPanelTest extends TestCase
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

        // Let the real `installed` middleware run (it would otherwise divert every
        // request to the setup page) without disabling any middleware — same as
        // tests/Modules/Core/BranchGoogleSettingsTest so the platform-shaped run
        // reaches the reservations page instead of redirecting to /setup.
        $this->app['config']->set('app.installed', true);

        // Production-shaped layout context so the owner theme page renders.
        $this->shareLayoutGlobals([
            'site_admin_logo' => 'logo.png',
            'site_logo_footer' => 'logo.png',
            'blog_enable' => 0,
            'enable_contact_page' => 0,
            'enable_faqs' => 0,
        ], true);
    }

    /** A plan-backed owner and their store. */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');

        // Store::created() auto-creates a default branch (#76). Drop it so the
        // P5-30 branch counts, ids and merchant-id rows below start from a known
        // zero-branch baseline — each test creates exactly the branches it asserts on.
        Branch::where('store_id', $store->id)->delete();

        return [$owner, $store];
    }

    /** An eligible branch (active + both coordinates). */
    private function branch(Store $store, string $name, float $lat, float $lng, ?string $placeId = null): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => $name, 'active' => 1,
            'accepting_orders' => 1, 'timezone' => 'UTC',
            'latitude' => $lat, 'longitude' => $lng, 'google_place_id' => $placeId,
        ]);
    }

    /** An active branch without coordinates (ineligible). */
    private function branchNoCoords(Store $store, string $name): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => $name, 'active' => 1,
            'accepting_orders' => 1, 'timezone' => 'UTC',
        ]);
    }

    /** An inactive branch (ineligible even with coordinates). */
    private function inactiveBranch(Store $store, string $name): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => $name, 'active' => 0,
            'accepting_orders' => 1, 'timezone' => 'UTC',
            'latitude' => 25.1, 'longitude' => 51.1,
        ]);
    }

    /** A real dashboard staff member of $store (manager). */
    private function staff(Store $store): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
    }

    /** A staff dashboard session exactly as StaffLoginController creates it. */
    private function asStaff(User $owner, StaffMember $staff): self
    {
        return $this->actingAs($staff, 'staff')->actingAs($owner, 'web')
            ->withSession(['staff_session_id' => $staff->id]);
    }

    public function test_owner_sees_merchant_ids_and_place_id_state_for_a_two_branch_store(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();
        $a = $this->branch($store, 'Riyadh', 24.7136, 46.6753, 'ChIJ-branch-a');
        $b = $this->branch($store, 'Jeddah', 21.4858, 39.1925);

        $response = $this->actingAs($owner)
            ->get(route('reservations.index', ['store_id' => $store->id]))
            ->assertOk();

        // Data contract: one branch row each, id-ordered, per-branch merchant ids.
        $branches = $response->viewData('rwg')['branches'];
        $this->assertCount(2, $branches);
        $this->assertSame([
            [
                'id' => (int) $a->id, 'name' => 'Riyadh',
                'merchant_id' => 'store-'.$store->id.'-b'.$a->id,
                'place_id' => 'ChIJ-branch-a', 'eligible' => true,
            ],
            [
                'id' => (int) $b->id, 'name' => 'Jeddah',
                'merchant_id' => 'store-'.$store->id.'-b'.$b->id,
                'place_id' => null, 'eligible' => true,
            ],
        ], $branches);

        // The panel renders both merchant ids and the set/missing place-id text.
        $response->assertSee('store-'.$store->id.'-b'.$a->id)
            ->assertSee('store-'.$store->id.'-b'.$b->id)
            ->assertSee('Place ID set')
            ->assertSee('ChIJ-branch-a')
            ->assertSee('Place ID missing')
            ->assertSee('Eligible for Google')
            ->assertSee('Google branches');
    }

    public function test_single_eligible_branch_store_keeps_the_store_merchant_id(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();
        // One eligible branch + ineligible siblings do not make it multi-merchant.
        $main = $this->branch($store, 'Main', 25.2854, 51.5310, 'ChIJ-main');
        $this->inactiveBranch($store, 'Closed');
        $this->branchNoCoords($store, 'NoGeo');

        $response = $this->actingAs($owner)
            ->get(route('reservations.index', ['store_id' => $store->id]))
            ->assertOk();

        $byId = collect($response->viewData('rwg')['branches'])->keyBy('id');
        $this->assertSame('store-'.$store->id, $byId[$main->id]['merchant_id'],
            'a one-eligible-branch store keeps the historic store-<id> merchant');
        $this->assertSame('ChIJ-main', $byId[$main->id]['place_id']);
        $this->assertTrue($byId[$main->id]['eligible']);

        $response->assertSee('store-'.$store->id)
            ->assertSee('Place ID set')
            ->assertSee('ChIJ-main')
            ->assertSee('Eligible for Google');
    }

    public function test_ineligible_branch_shows_not_published_on_the_panel(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();
        $live = $this->branch($store, 'Live', 25.1, 51.1, 'ChIJ-live');
        $inactive = $this->inactiveBranch($store, 'Closed');
        $noGeo = $this->branchNoCoords($store, 'NoGeo');

        $response = $this->actingAs($owner)
            ->get(route('reservations.index', ['store_id' => $store->id]))
            ->assertOk();

        $byId = collect($response->viewData('rwg')['branches'])->keyBy('id');
        // Only the eligible branch carries a merchant id (single eligible ⇒ store id).
        $this->assertSame('store-'.$store->id, $byId[$live->id]['merchant_id']);
        $this->assertNull($byId[$inactive->id]['merchant_id'], 'an inactive branch is not published');
        $this->assertFalse($byId[$inactive->id]['eligible']);
        $this->assertNull($byId[$noGeo->id]['merchant_id'], 'a branch without coordinates is not published');
        $this->assertFalse($byId[$noGeo->id]['eligible']);

        // Both ineligible branches render the "Not published" text, and the
        // eligibility cell explains why the branch is not live on Google.
        $response->assertSee('Not published')
            ->assertSee('Not eligible: the branch must be active and have coordinates.');
    }

    public function test_another_stores_branches_are_never_shown(): void
    {
        $this->withoutMiddleware();
        [$ownerA, $storeA] = $this->owner();
        [, $storeB] = $this->owner();
        $a = $this->branch($storeA, 'A-Main', 25.1, 51.1);
        $this->branch($storeB, 'B-Main', 25.2, 51.2, 'ChIJ-b');

        $response = $this->actingAs($ownerA)
            ->get(route('reservations.index', ['store_id' => $storeA->id]))
            ->assertOk();

        // Data contract: only store A's branch, and no foreign merchant id rendered.
        $branches = $response->viewData('rwg')['branches'];
        $this->assertCount(1, $branches);
        $this->assertSame($a->id, $branches[0]['id']);
        $this->assertSame('store-'.$storeA->id, $branches[0]['merchant_id']);

        $response->assertSee('A-Main')
            ->assertSee('store-'.$storeA->id)
            ->assertDontSee('store-'.$storeB->id)
            ->assertDontSee('B-Main')
            ->assertDontSee('ChIJ-b');
    }

    // ── Real-middleware tenancy: no withoutMiddleware() ──────────────────────
    // The tests above run with every middleware disabled (controller-level data),
    // which proves the branches are computed store-scoped but NOT that the shipped
    // dashboard middleware stack refuses a cross-tenant read. These run the FULL
    // stack (web + app.locale + auth + staff.dashboard + dashboard.sync +
    // reseller.suspension + abuse.hold + csp.dashboard + feature:reservations).

    /**
     * (a) A staff member of store A — staff.dashboard session, real middleware —
     * opens store B's reservations page. Refused; the middleware default-denies
     * the unmapped route (abort 403) before the controller can run.
     */
    public function test_staff_cannot_open_the_reservations_page_for_a_store_they_do_not_own(): void
    {
        [$ownerA, $storeA] = $this->owner();
        [, $storeB] = $this->owner();
        $this->branch($storeB, 'B-Main', 25.2, 51.2, 'ChIJ-b');
        $staff = $this->staff($storeA);

        $response = $this->asStaff($ownerA, $staff)
            ->get(route('reservations.index', ['store_id' => $storeB->id]));

        $this->assertRefused($response);
        $this->assertSame(1, Branch::where('store_id', $storeB->id)->count(), 'store B\'s branch is never touched');
    }

    /**
     * (b) A different, plan-backed owner (own store B) opens store A's reservations
     * page with a store A query. Refused by the controller's owner guard through the
     * real middleware stack, so store A's branches are never served to them.
     */
    public function test_other_owner_cannot_read_another_stores_branches(): void
    {
        [$ownerB] = $this->owner();
        [, $storeA] = $this->owner();
        $this->branch($storeA, 'A-Main', 25.1, 51.1, 'ChIJ-a');

        $response = $this->actingAs($ownerB)
            ->get(route('reservations.index', ['store_id' => $storeA->id]));

        $this->assertRefused($response);
        $this->assertSame(1, Branch::where('store_id', $storeA->id)->count(), 'store A\'s branch is never exposed');
    }

    /**
     * Positive control: the real owner reading their OWN store through the real
     * middleware stack succeeds and the panel still carries the branch merchant id —
     * so the refusals above are tenancy, not a broken route or environment.
     */
    public function test_owner_sees_branch_merchant_with_real_middleware(): void
    {
        [$owner, $store] = $this->owner();
        $this->branch($store, 'Main', 25.1, 51.1);

        $response = $this->actingAs($owner)
            ->get(route('reservations.index', ['store_id' => $store->id]))
            ->assertOk();

        $this->assertSame('store-'.$store->id, $response->viewData('rwg')['branches'][0]['merchant_id']);
        $response->assertSee('store-'.$store->id)->assertSee('Eligible for Google');
    }

    /** A refused page read: hard 403 (controller/middleware abort) or a non-success redirect. */
    private function assertRefused(TestResponse $response): void
    {
        $this->assertContains($response->getStatusCode(), [302, 403], 'a cross-tenant page read must be refused');

        if ($response->getStatusCode() === 302) {
            $this->assertStringNotContainsString('reservations', (string) $response->headers->get('Location'));
            $this->assertEmpty(session('success'));
        }
    }
}
