<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * M2 — the "Notification preferences" dashboard page + save endpoint.
 *
 * The page lists only the logged-in owner's stores/branches; the save endpoint
 * runs the M1 IDOR fence so a foreign store id in the POST body can never be
 * persisted (or its existence probed).
 */
class DashNotifPrefsPageTest extends TestCase
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
        // The dashboard chrome reads these from a wildcard composer gated on
        // app_is_installed() (false in the test env) — share them as the sibling
        // dashboard page-render tests do.
        View::share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware();
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $o, string $title): Store
    {
        return Store::create([
            'user_id' => $o->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_page_lists_only_the_owners_stores_and_branches(): void
    {
        $owner = $this->makeOwner();
        $mine = $this->makeStore($owner, 'MyDiner');
        Branch::create(['store_id' => $mine->id, 'name' => 'Downtown', 'active' => 1]);

        $stranger = $this->makeOwner();
        $this->makeStore($stranger, 'StrangerCafe');

        $res = $this->actingAs($owner)->get(route('dashboard.notifPrefs'));

        $res->assertOk();
        $res->assertSee('MyDiner');
        $res->assertSee('Downtown');
        $res->assertDontSee('StrangerCafe');   // another owner's store never appears
    }

    /**
     * The window.notifPrefs factory (the page's x-data) must NOT sit on a
     * data-turbo-eval="false" script — Turbo skips those on link-navigation, so
     * the factory is never defined and Alpine renders a blank page. Regression for
     * the live "notifPrefs is not defined" bug the automated response-body checks
     * could not see (the store JSON is present either way).
     */
    public function test_notifprefs_factory_script_is_turbo_evaluated(): void
    {
        $owner = $this->makeOwner();
        $this->makeStore($owner, 'MyDiner');

        $html = $this->actingAs($owner)->get(route('dashboard.notifPrefs'))->getContent();

        // The factory script now carries a CSP nonce (dashboard nonce sweep), so the
        // tag is `<script nonce="…">` — allow that attribute, but line 2 still pins the
        // real contract: it must NOT be a data-turbo-eval="false" script.
        $this->assertMatchesRegularExpression('/<script(?:\s+nonce="[^"]*")?>\s*window\.notifPrefs\s*=/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-turbo-eval="false"[^>]*>\s*window\.notifPrefs/', $html);
    }

    public function test_save_persists_prefs_through_the_endpoint(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'MyDiner');

        $res = $this->actingAs($owner)->postJson(route('dashboard.notifPrefs.save'), [
            'prefs' => ['stores' => [
                (string) $store->id => ['enabled' => false, 'events' => ['orders' => false]],
            ]],
        ]);

        $res->assertOk()->assertJsonPath('ok', true);

        $stored = json_decode(UserOption::getUserOption($owner->id, 'dashnotif_prefs'), true);
        $this->assertFalse($stored['stores'][(string) $store->id]['enabled']);
        $this->assertFalse($stored['stores'][(string) $store->id]['events']['orders']);
    }

    /** MANDATORY attacker test: a foreign store id in the POST body is fenced out. */
    public function test_foreign_store_in_post_is_rejected(): void
    {
        $owner = $this->makeOwner();
        $mine = $this->makeStore($owner, 'MyDiner');

        $victim = $this->makeOwner();
        $theirStore = $this->makeStore($victim, 'VictimCafe');

        $res = $this->actingAs($owner)->postJson(route('dashboard.notifPrefs.save'), [
            'prefs' => ['stores' => [
                (string) $mine->id => ['enabled' => true],
                (string) $theirStore->id => ['enabled' => false],   // injected foreign store
            ]],
        ]);

        $res->assertOk();

        $stored = json_decode(UserOption::getUserOption($owner->id, 'dashnotif_prefs'), true);
        $this->assertArrayHasKey((string) $mine->id, $stored['stores']);
        $this->assertArrayNotHasKey((string) $theirStore->id, $stored['stores']);

        // The victim's own prefs row is never written.
        $this->assertFalse((bool) UserOption::getUserOption($victim->id, 'dashnotif_prefs'));
    }
}
