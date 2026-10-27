<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane D (D6): waitlist dashboard RwG card + source badges +
 * lane-owned settings save.
 */
class RwgWaitlistDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Production-shaped layout context (stdClass via ViewServiceProvider), not a
        // hand-rolled double — keeps ViewComposerContractTest's cap intact.
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'Plan ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** With RwG creds present the card + Google-source badge render. */
    public function test_card_and_badge_show_when_configured(): void
    {
        config(['services.reserve_with_google.enabled' => true]);
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        WaitlistEntry::create(['store_id' => $r->id, 'rwg_entry_id' => 'g1', 'name' => 'Googler', 'party_size' => 2, 'status' => 'waiting', 'source' => 'google', 'quoted_wait' => 20]);

        $res = $this->actingAs($owner)->get(route('waitlist.index', $r->id));
        $res->assertOk();
        $res->assertSee('Reserve with Google');
        $res->assertSeeHtml('rwg_waitlist_enabled'); // the toggle input
        $res->assertSee('Google'); // source badge on the row
    }

    /** Dark: no RwG card when creds absent (per-store toggle has no meaning yet). */
    public function test_card_hidden_when_dark(): void
    {
        config(['services.reserve_with_google.enabled' => false]);
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);

        $res = $this->actingAs($owner)->get(route('waitlist.index', $r->id));
        $res->assertOk();
        $res->assertDontSee('Reserve with Google');
    }

    /** Lane-owned save persists the toggle + max party (STRING '1'/'0'). */
    public function test_save_rwg_settings_persists(): void
    {
        config(['services.reserve_with_google.enabled' => true]);
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('waitlist.rwg-settings', $r->id), [
            'rwg_waitlist_enabled' => '1',
            'waitlist_max_party'   => '8',
        ])->assertRedirect();

        $this->assertSame('1', StoreSettings::legacy((int) $r->id, 'rwg_waitlist_enabled'));
        $this->assertSame('8', StoreSettings::legacy((int) $r->id, 'waitlist_max_party'));

        // Unchecking writes '0'.
        $this->actingAs($owner)->post(route('waitlist.rwg-settings', $r->id), ['waitlist_max_party' => '8'])->assertRedirect();
        $this->assertSame('0', StoreSettings::legacy((int) $r->id, 'rwg_waitlist_enabled'));
    }

    /** Tenant isolation: a different owner cannot save my store's RwG settings. */
    public function test_save_rwg_settings_tenant_isolation(): void
    {
        config(['services.reserve_with_google.enabled' => true]);
        $owner = $this->makeOwner();
        $r = $this->makeRestaurant($owner);
        $other = $this->makeOwner();

        $this->actingAs($other)->post(route('waitlist.rwg-settings', $r->id), ['rwg_waitlist_enabled' => '1'])
            ->assertNotFound();
        $this->assertNull(StoreSettings::legacy((int) $r->id, 'rwg_waitlist_enabled'));
    }
}
