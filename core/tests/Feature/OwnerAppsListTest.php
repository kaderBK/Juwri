<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\App;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\AppInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Modules\Auth\Models\OAuthClient as Client;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * THEME-AUDIT Lane C (C2/C4): the owner "Installed apps" surface must render the
 * installed marketplace apps and let each one open/configure without error.
 *
 * The showcase demo (#10008) has all 15 approved apps installed active. This
 * asserts the OWNER-facing contract through the controller: the apps list page
 * loads and lists installs, an embeddable app's iframe host page opens, and its
 * short-lived App-Bridge session token mints — mirroring several apps rather
 * than reproducing the exact 15 dev-DB rows (F0 verifies those in Chrome).
 */
class OwnerAppsListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->artisan('passport:keys', ['--force' => true])->run();

        // Layout globals in production's exact stdClass shape (ViewServiceProvider
        // parity) — never a hand-rolled double (ViewComposerContractTest / WI-M12).
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
            \Juwri\Modules\Auth\Http\Middleware\Authenticate::class,
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
            \Juwri\Core\Http\Middleware\SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            \App\Http\Middleware\AbuseHoldWall::class,
            \Juwri\Core\Http\Middleware\AddDashboardCspHeaders::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $owner;
    }

    /** An approved marketplace app + Passport client; optionally embeddable. */
    private function approvedApp(string $name, ?string $embedUrl = null): App
    {
        $client = Client::create([
            'name' => $name, 'secret' => Str::random(40), 'provider' => 'users',
            'redirect' => 'https://' . Str::slug($name) . '.test/cb',
            'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);

        return App::create([
            'name' => $name, 'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            'scopes' => ['menu:read'], 'oauth_client_id' => $client->id,
            'status' => 'approved', 'embed_url' => $embedUrl,
        ]);
    }

    public function test_apps_list_shows_installed_apps(): void
    {
        $owner = $this->owner();
        $service = new AppInstallService();

        // A representative slice of the demo's installed apps, incl. an embeddable one.
        $names = ['Kiosk', 'Accounting', 'Guest CRM', 'Driver App'];
        foreach ($names as $n) {
            $app = $this->approvedApp($n, $n === 'Kiosk' ? 'https://kiosk-partner.test/embed' : null);
            $service->install($app, $owner);
        }

        $res = $this->actingAs($owner)->get(route('apps.page'))->assertOk();
        $res->assertSee(___('Installed apps'));
        foreach ($names as $n) {
            $res->assertSee($n);
        }
        // Active installs are marked as such.
        $res->assertSee(___('Installed'));
    }

    public function test_embeddable_app_opens_its_iframe_host(): void
    {
        $owner = $this->owner();
        $app = $this->approvedApp('Kiosk', 'https://kiosk-partner.test/embed');
        new AppInstallService()->install($app, $owner);

        $res = $this->actingAs($owner)->get(route('apps.embed', $app->id))->assertOk();
        $res->assertSeeHtml('kiosk-partner.test/embed');
    }

    public function test_embeddable_app_mints_a_session_token(): void
    {
        $owner = $this->owner();
        $app = $this->approvedApp('Kiosk', 'https://kiosk-partner.test/embed');
        new AppInstallService()->install($app, $owner);

        $this->actingAs($owner)->getJson(route('apps.sessionToken', $app->id))
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_in']);
    }

    public function test_uninstalled_app_cannot_open_embed(): void
    {
        $owner = $this->owner();
        $app = $this->approvedApp('Kiosk', 'https://kiosk-partner.test/embed');
        $install = new AppInstallService()->install($app, $owner);
        $install->update(['status' => 'uninstalled', 'uninstalled_at' => now()]);

        // No active install → the embed host 404s (no cross-tenant / stale open).
        $this->actingAs($owner)->get(route('apps.embed', $app->id))->assertNotFound();
    }
}
