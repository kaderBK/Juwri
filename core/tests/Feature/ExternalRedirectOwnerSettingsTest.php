<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B1 — the owner settings surface. Saves the redirect fields through the real
 * owner update() controller (HTTP PUT restaurants.update), which routes them to
 * the shared ExternalRedirect::saveFromRequest(). Pins persistence + audit and
 * the invalid-URL rejection (nothing persisted on reject).
 */
class ExternalRedirectOwnerSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    public function test_owner_save_persists_redirect_options_and_audits(): void
    {
        [$owner, $store] = $this->makeStore();

        $this->actingAs($owner)->put(route('stores.update', $store->id), $this->payload([
            'external_redirect_enabled' => '1',
            'external_redirect_url' => 'https://legacy.example.com/{slug}{query}',
            'external_redirect_interstitial' => '1',
        ]));

        $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
        $this->assertSame('https://legacy.example.com/{slug}{query}', (string) StoreOption::getStoreOption($store->id, 'external_redirect_url'));
        $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_interstitial'));

        $log = ActivityLog::where('action', 'external_redirect.set')
            ->where('store_id', $store->id)->first();
        $this->assertNotNull($log, 'A set must write an ActivityLog row.');
        $this->assertSame($owner->id, (int) $log->actor_user_id);
    }

    public function test_owner_save_rejects_an_invalid_url_and_persists_nothing(): void
    {
        [$owner, $store] = $this->makeStore();

        $response = $this->actingAs($owner)->from(route('stores.edit', $store->id))
            ->put(route('stores.update', $store->id), $this->payload([
                'external_redirect_enabled' => '1',
                'external_redirect_url' => 'http://insecure.example.com', // not https
                'external_redirect_interstitial' => '0',
            ]));

        $response->assertRedirect(route('stores.edit', $store->id));
        // The invalid save aborted before any write — the toggle never turned on.
        $this->assertNotSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
        $this->assertNotSame('http://insecure.example.com', (string) StoreOption::getStoreOption($store->id, 'external_redirect_url'));
    }

    public function test_owner_edit_page_renders_the_redirect_section_with_current_values(): void
    {
        [$owner, $store] = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', 'https://legacy.example.com/{slug}');

        // Mirror the shared globals the real app boots with (themes read them).
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();

        $response = $this->actingAs($owner)->get(route('stores.edit', $store->id));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('External redirect'));
        // Loose-compare: an enabled store renders its menu toggle as checked and
        // its saved URL pre-filled.
        $response->assertSeeHtml('name="external_redirect_enabled" value="1" checked');
        $response->assertSeeHtml('https://legacy.example.com/{slug}');
    }

    public function test_saving_with_redirect_off_writes_no_spurious_options_or_audit(): void
    {
        [$owner, $store] = $this->makeStore();

        // The store never used external redirect; the owner saves the settings form
        // with every redirect toggle off (the default payload). This must be a true
        // no-op — no option rows written, no external_redirect.* audit rows.
        $this->actingAs($owner)->put(route('stores.update', $store->id), $this->payload([]));

        $this->assertSame(0, ActivityLog::where('store_id', $store->id)
            ->where('action', 'like', 'external_redirect.%')->count());
        $this->assertFalse(
            StoreSettings::has((int) $store->id, 'external_redirect_enabled')
        );
    }

    public function test_owner_can_enable_bio_redirect_independently(): void
    {
        [$owner, $store] = $this->makeStore();

        $this->actingAs($owner)->put(route('stores.update', $store->id), $this->payload([
            'bio_external_redirect_enabled' => '1',
            'bio_external_redirect_url' => 'https://bio.example.com/{slug}',
        ]));

        $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'bio_external_redirect_enabled'));
        $this->assertSame('https://bio.example.com/{slug}', (string) StoreOption::getStoreOption($store->id, 'bio_external_redirect_url'));
        // Menu redirect untouched.
        $this->assertNotSame('1', (string) StoreOption::getStoreOption($store->id, 'external_redirect_enabled'));
    }

    /**
     * The full valid owner-update payload the controller's validator requires,
     * merged with the redirect fields under test.
     *
     * @param  array<string, string>  $redirect
     * @return array<string, mixed>
     */
    private function payload(array $redirect): array
    {
        return array_merge([
            'color' => '#111',
            'title' => 'My Store',
            'description' => 'A description',
            'address' => '1 Main St',
            'store_template' => 'juwri',
            'currency_code' => 'USD',
            'currency_sign' => '$',
            'currency_pos' => 0,
            // Redirect defaults (checkbox surfaces send a hidden '0' when unchecked).
            'external_redirect_enabled' => '0',
            'external_redirect_url' => '',
            'external_redirect_interstitial' => '0',
            'bio_external_redirect_enabled' => '0',
            'bio_external_redirect_url' => '',
        ], $redirect);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        // Grandfather the current template so the update validator accepts it.
        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');

        return [$owner, $store];
    }
}
