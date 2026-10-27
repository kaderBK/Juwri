<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\RememberLocale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — C7 dashboard RwG card + admin oversight.
 */
class RwgDashboardTest extends TestCase
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
        // admin composer vars — so both the owner theme page and the admin oversight view
        // render. Using the shared helper keeps ViewComposerContractTest's cap intact.
        // The admin chrome reads a few extra settings keys the stdClass must carry.
        $this->shareLayoutGlobals([
            'site_admin_logo' => 'logo.png',
            'site_logo_footer' => 'logo.png',
            'blog_enable' => 0,
            'enable_contact_page' => 0,
            'enable_faqs' => 0,
        ], true);
    }

    /** Disable env/locale middleware but KEEP admin/auth so guards are exercised. */
    private function keepGuards(): void
    {
        $this->withoutMiddleware([
            Installed::class,
            CheckUserIsBanned::class,
            RememberLocale::class,
            PreventRequestForgery::class,
            LaravelLocalizationRoutes::class,
            LaravelLocalizationRedirectFilter::class,
        ]);
    }

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

    private function admin(): User
    {
        return User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_reservations_page_renders_rwg_card(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();

        $html = $this->actingAs($owner)
            ->get(route('reservations.index', ['store_id' => $store->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Reserve with Google', $html);
        $this->assertStringContainsString('reservation_covers_per_slot', $html);
    }

    public function test_owner_saves_rwg_settings(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();

        $this->actingAs($owner)->post(route('reservations.settings'), [
            'store_id' => $store->id,
            'settings_form' => 'rwg',
            'rwg_reservations_enabled' => '1',
            'reservation_slot_interval' => 45,
            'reservation_covers_per_slot' => 20,
            'reservation_max_party' => 10,
            'reservation_horizon_days' => 60,
        ])->assertRedirect();

        $this->assertSame('1', (string) store_options($store->id, 'rwg_reservations_enabled'));
        $this->assertSame('45', (string) store_options($store->id, 'reservation_slot_interval'));
        $this->assertSame('20', (string) store_options($store->id, 'reservation_covers_per_slot'));
        $this->assertSame('10', (string) store_options($store->id, 'reservation_max_party'));
        $this->assertSame('60', (string) store_options($store->id, 'reservation_horizon_days'));
    }

    public function test_rwg_save_does_not_reset_buffer(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();
        StoreOption::updateStoreOption($store->id, 'reservation_buffer_minutes', '120');

        $this->actingAs($owner)->post(route('reservations.settings'), [
            'store_id' => $store->id, 'settings_form' => 'rwg',
            'rwg_reservations_enabled' => '1', 'reservation_covers_per_slot' => 8,
        ])->assertRedirect();

        // The buffer/blackout card was untouched.
        $this->assertSame('120', (string) store_options($store->id, 'reservation_buffer_minutes'));
    }

    public function test_buffer_save_still_works_without_marker(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();

        // No settings_form ⇒ back-compat buffer/blackout path.
        $this->actingAs($owner)->post(route('reservations.settings'), [
            'store_id' => $store->id, 'reservation_buffer_minutes' => 75, 'blackouts' => '',
        ])->assertRedirect();

        $this->assertSame('75', (string) store_options($store->id, 'reservation_buffer_minutes'));
        // RwG toggle untouched (never set).
        $this->assertFalse((bool) store_options($store->id, 'rwg_reservations_enabled'));
    }

    public function test_owner_sets_changes_and_clears_the_google_place_id(): void
    {
        $this->withoutMiddleware();
        [$owner, $store] = $this->owner();
        [, $other] = $this->owner();
        $save = fn (array $extra, $as = null) => $this->actingAs($as ?? $owner)->post(route('reservations.settings'), ['store_id' => $store->id, 'settings_form' => 'rwg', 'rwg_reservations_enabled' => '1'] + $extra);

        $save(['google_place_id' => ' ChIJN1t_tDeuEmsRUsoyG83frY4 '])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $store->fresh()->google_place_id);
        $this->actingAs($owner)->get(route('reservations.index', ['store_id' => $store->id]))->assertSee('ChIJN1t_tDeuEmsRUsoyG83frY4');

        $save(['google_place_id' => 'not a place id!'])->assertSessionHasErrors('google_place_id');
        $this->assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $store->fresh()->google_place_id);

        $other->forceFill(['google_place_id' => 'ChIJotherOTHERother-1'])->save();
        $save(['google_place_id' => 'ChIJotherOTHERother-1'])->assertSessionHasErrors('google_place_id');
        $this->assertSame('ChIJotherOTHERother-1', $other->fresh()->google_place_id, 'another store keeps its place ID');

        $save([])->assertRedirect(); // a save without the field leaves it alone
        $this->assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $store->fresh()->google_place_id);
        $save(['google_place_id' => ''])->assertRedirect();
        $this->assertNull($store->fresh()->google_place_id);

        [$stranger] = $this->owner();
        $save(['google_place_id' => 'ChIJstrangerSTRANGER'], $stranger)->assertForbidden();
        $this->assertNull($store->fresh()->google_place_id);
    }

    public function test_save_settings_cross_tenant_forbidden(): void
    {
        $this->withoutMiddleware();
        [$ownerA] = $this->owner();
        [, $storeB] = $this->owner();

        $this->actingAs($ownerA)->post(route('reservations.settings'), [
            'store_id' => $storeB->id, 'settings_form' => 'rwg', 'rwg_reservations_enabled' => '1',
        ])->assertForbidden();
    }

    public function test_admin_oversight_renders_for_admin(): void
    {
        $this->keepGuards();
        [, $store] = $this->owner();
        StoreOption::updateStoreOption($store->id, 'rwg_reservations_enabled', '1');

        $html = $this->actingAs($this->admin())
            ->get(route('admin.reserve-google.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Reserve with Google', $html);
        $this->assertStringContainsString($store->title, $html);
    }

    public function test_admin_oversight_forbidden_for_non_admin(): void
    {
        $this->keepGuards();
        [$owner] = $this->owner();

        $res = $this->actingAs($owner)->get(route('admin.reserve-google.index'));

        $this->assertNotSame(200, $res->getStatusCode()); // admin guard blocks (403/redirect)
    }

    public function test_admin_kill_disables_store(): void
    {
        $this->keepGuards();
        [, $store] = $this->owner();
        StoreOption::updateStoreOption($store->id, 'rwg_reservations_enabled', '1');

        $this->actingAs($this->admin())
            ->post(route('admin.reserve-google.kill', $store->id))
            ->assertRedirect();

        $this->assertSame('0', (string) store_options($store->id, 'rwg_reservations_enabled'));
    }
}
