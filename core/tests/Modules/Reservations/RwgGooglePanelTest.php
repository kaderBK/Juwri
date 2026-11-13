<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\StaffMember;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-23: the store's "Google" panel — ordering toggle + cancel window save, and the
 * platform-wide last feed-drop status surfaced on the reservations page.
 */
class RwgGooglePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Production-shaped layout context (stdClass, not a hand-rolled double) plus the
        // admin composer vars — so the owner theme page renders.
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

        return [$owner, $store];
    }

    /** A verified user who owns no store. */
    private function nonOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 0, 'menu_limit' => 0]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /**
     * A real dashboard staff member of $store. Dashboard staff are App\Models\StaffMember,
     * not the Bookings module's BookingStaff (which is a bookable resource). Mirrors how
     * tests/Modules/Platform/StaffRbacHardeningTest builds one: owner_id + store_id + a role.
     */
    private function staff(Store $store, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    /**
     * A staff dashboard session exactly as StaffLoginController creates it: the web guard is
     * the OWNER (the dashboard is owner-scoped), the staff guard is the staff row, and
     * staff_session_id marks the session — which is what is_staff_dashboard() keys on.
     */
    private function asStaff(User $owner, StaffMember $staff): self
    {
        return $this->actingAs($staff, 'staff')->actingAs($owner, 'web')
            ->withSession(['staff_session_id' => $staff->id]);
    }

    /** The Google-panel payload: ordering toggle + cancel window against one store. */
    private function rwgPayload(int $storeId, string $ordering, int $cancelWindow): array
    {
        return [
            'store_id' => $storeId,
            'settings_form' => 'rwg',
            'rwg_reservations_enabled' => '1',
            'google_ordering_enabled' => $ordering,
            'reservation_cancel_window_minutes' => $cancelWindow,
        ];
    }

    /**
     * A refused write: a hard 403 (the controller/middleware abort) or a redirect that is
     * demonstrably NOT the success path (never a success flash, never back onto reservations).
     */
    private function assertWriteRefused(TestResponse $response): void
    {
        $this->assertContains($response->getStatusCode(), [302, 403], 'the cross-tenant write must be refused');

        if ($response->getStatusCode() === 302) {
            $location = (string) $response->headers->get('Location');
            $this->assertStringNotContainsString('reservations', $location, 'a refused save must not redirect to the panel');
            $this->assertStringNotContainsString('success', $location);
            $this->assertEmpty(session('success'), 'a refused save must not flash success');
        }
    }

    public function test_owner_saves_ordering_and_cancel_window(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();

        $this->actingAs($owner)->post(route('reservations.settings'), [
            'store_id' => $store->id,
            'settings_form' => 'rwg',
            'rwg_reservations_enabled' => '1',
            'google_ordering_enabled' => '1',
            'reservation_cancel_window_minutes' => 90,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('1', (string) store_options($store->id, 'google_ordering_enabled'));
        $this->assertSame('90', (string) store_options($store->id, 'reservation_cancel_window_minutes'));
    }

    public function test_ordering_unchecked_saves_zero(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();
        StoreOption::updateStoreOption($store->id, 'google_ordering_enabled', '1');

        // No google_ordering_enabled key in the payload ⇒ unchecked.
        $this->actingAs($owner)->post(route('reservations.settings'), [
            'store_id' => $store->id, 'settings_form' => 'rwg', 'rwg_reservations_enabled' => '1',
        ])->assertRedirect();

        $this->assertSame('0', (string) store_options($store->id, 'google_ordering_enabled'));
    }

    public function test_cancel_window_out_of_range_rejected_and_option_unchanged(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();
        StoreOption::updateStoreOption($store->id, 'reservation_cancel_window_minutes', '30');

        foreach ([20000, -1] as $bad) {
            $this->actingAs($owner)->post(route('reservations.settings'), [
                'store_id' => $store->id, 'settings_form' => 'rwg',
                'rwg_reservations_enabled' => '1', 'reservation_cancel_window_minutes' => $bad,
            ])->assertSessionHasErrors('reservation_cancel_window_minutes');
        }

        $this->assertSame('30', (string) store_options($store->id, 'reservation_cancel_window_minutes'));
    }

    public function test_cross_tenant_save_forbidden_and_options_unchanged(): void
    {
        $this->withoutMiddleware();
        [$ownerA] = $this->owner();
        [, $storeB] = $this->owner();
        StoreOption::updateStoreOption($storeB->id, 'google_ordering_enabled', '1');
        StoreOption::updateStoreOption($storeB->id, 'reservation_cancel_window_minutes', '60');

        $this->actingAs($ownerA)->post(route('reservations.settings'), [
            'store_id' => $storeB->id, 'settings_form' => 'rwg',
            'rwg_reservations_enabled' => '1', 'google_ordering_enabled' => '0',
            'reservation_cancel_window_minutes' => 5,
        ])->assertForbidden();

        $this->assertSame('1', (string) store_options($storeB->id, 'google_ordering_enabled'));
        $this->assertSame('60', (string) store_options($storeB->id, 'reservation_cancel_window_minutes'));
    }

    public function test_non_owner_refused_and_options_unchanged(): void
    {
        $this->withoutMiddleware();
        [, $store] = $this->owner();
        $stranger = $this->nonOwner();
        StoreOption::updateStoreOption($store->id, 'google_ordering_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_cancel_window_minutes', '60');

        $this->actingAs($stranger)->post(route('reservations.settings'), [
            'store_id' => $store->id, 'settings_form' => 'rwg',
            'rwg_reservations_enabled' => '1', 'google_ordering_enabled' => '0',
            'reservation_cancel_window_minutes' => 5,
        ])->assertForbidden();

        $this->assertSame('1', (string) store_options($store->id, 'google_ordering_enabled'));
        $this->assertSame('60', (string) store_options($store->id, 'reservation_cancel_window_minutes'));
    }

    public function test_page_shows_saved_values_and_last_drop_status(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();

        $this->actingAs($owner)->post(route('reservations.settings'), [
            'store_id' => $store->id, 'settings_form' => 'rwg',
            'rwg_reservations_enabled' => '1', 'google_ordering_enabled' => '1',
            'reservation_cancel_window_minutes' => 90,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Option values are the source of truth (the view worker owns the markup).
        $this->assertSame('1', (string) store_options($store->id, 'google_ordering_enabled'));
        $this->assertSame('90', (string) store_options($store->id, 'reservation_cancel_window_minutes'));

        PlatformSettings::set('rwg_last_drop', json_encode([
            'at' => now()->toIso8601String(), 'status' => 'failed', 'problem_count' => 2,
        ]));

        $response = $this->actingAs($owner)->get(route('reservations.index', ['store_id' => $store->id]))->assertOk();

        $rwg = $response->viewData('rwg');
        $this->assertTrue($rwg['ordering']);
        $this->assertSame(90, $rwg['cancelWindow']);
        $this->assertSame('failed', $rwg['lastDrop']['status']);
        $this->assertSame(2, $rwg['lastDrop']['problems']);
        $this->assertInstanceOf(Carbon::class, $rwg['lastDrop']['at']);
    }

    public function test_page_last_drop_null_when_not_run(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();

        $response = $this->actingAs($owner)->get(route('reservations.index', ['store_id' => $store->id]))->assertOk();

        $this->assertNull($response->viewData('rwg')['lastDrop']);
    }

    // ── Real-middleware tenancy: no withoutMiddleware(), no mocked gate ───────────
    // The tests above run with every middleware disabled, which proves the
    // controller's own store_id guard but NOT the dashboard auth stack. These three
    // run the FULL stack (web + app.locale + auth + staff.dashboard + dashboard.sync
    // + reseller.suspension + abuse.hold + csp.dashboard + feature:reservations), so a
    // refusal is produced by the shipped middleware/gate, not by the test harness.

    /**
     * (a) A staff member of store A — staff.dashboard session, real middleware — tries
     * to save the Google panel for store B, a store neither the staff member nor their
     * session owner owns. Refused, and store B's stored options are untouched.
     */
    public function test_staff_cannot_save_rwg_settings_for_a_store_they_do_not_own(): void
    {
        [$ownerA, $storeA] = $this->owner();
        [, $storeB] = $this->owner();
        $staff = $this->staff($storeA);

        StoreOption::updateStoreOption($storeB->id, 'google_ordering_enabled', '1');
        StoreOption::updateStoreOption($storeB->id, 'reservation_cancel_window_minutes', '60');

        $response = $this->asStaff($ownerA, $staff)
            ->post(route('reservations.settings'), $this->rwgPayload($storeB->id, '0', 5));

        $this->assertWriteRefused($response);

        $this->assertSame('1', (string) store_options($storeB->id, 'google_ordering_enabled'));
        $this->assertSame('60', (string) store_options($storeB->id, 'reservation_cancel_window_minutes'));
    }

    /**
     * (b) A different, plan-backed owner (own store B) tries to save store A's Google
     * panel. Refused, and store A's stored options are untouched.
     */
    public function test_other_owner_cannot_save_rwg_settings_for_a_store_they_do_not_own(): void
    {
        [, $storeA] = $this->owner();
        [$ownerB] = $this->owner();

        StoreOption::updateStoreOption($storeA->id, 'google_ordering_enabled', '1');
        StoreOption::updateStoreOption($storeA->id, 'reservation_cancel_window_minutes', '60');

        $response = $this->actingAs($ownerB)
            ->post(route('reservations.settings'), $this->rwgPayload($storeA->id, '0', 5));

        $this->assertWriteRefused($response);

        $this->assertSame('1', (string) store_options($storeA->id, 'google_ordering_enabled'));
        $this->assertSame('60', (string) store_options($storeA->id, 'reservation_cancel_window_minutes'));
    }

    /**
     * Positive control: the real owner saving the SAME payload through the REAL
     * middleware stack succeeds and the values change — so the refusals above are
     * tenancy, not a broken route or environment.
     */
    public function test_owner_save_with_real_middleware_succeeds_and_changes_values(): void
    {
        [$owner, $store] = $this->owner();
        StoreOption::updateStoreOption($store->id, 'google_ordering_enabled', '0');
        StoreOption::updateStoreOption($store->id, 'reservation_cancel_window_minutes', '60');

        $this->actingAs($owner)
            ->post(route('reservations.settings'), $this->rwgPayload($store->id, '1', 90))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('1', (string) store_options($store->id, 'google_ordering_enabled'));
        $this->assertSame('90', (string) store_options($store->id, 'reservation_cancel_window_minutes'));
    }
}
