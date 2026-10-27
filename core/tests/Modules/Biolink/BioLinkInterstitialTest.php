<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-RUN A5 (SPEC §5): the bio-first / bio-only interstitial and its FULL
 * bypass matrix. Every never-intercept row is a case here, including G2 — a qr
 * scan (`?qr-id=`) must reach publicView so it is counted (StoreView) and
 * limit-enforced, under BOTH bio_first and bio_only.
 */
class BioLinkInterstitialTest extends TestCase
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

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0:User,1:Store} */
    private function makeStore(string $mode): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'allow_ordering' => 1, 'biolink' => 1,
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234']);

        StoreOption::updateStoreOption($store->id, 'store_template', 'juwri');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($owner, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode([
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => $mode,
        ]));
        StoreOption::flushOptionMemo();

        return [$owner, $store];
    }

    private function assertNotRedirectedToBio($res): void
    {
        $this->assertStringNotContainsString('/bio', (string) $res->headers->get('Location'));
    }

    public function test_menu_first_never_intercepts(): void
    {
        [, $store] = $this->makeStore('menu_first');

        $res = $this->get('/'.$store->slug);

        $this->assertLessThan(400, $res->status());
        $this->assertNotRedirectedToBio($res);
    }

    public function test_bio_only_always_redirects_to_bio(): void
    {
        [, $store] = $this->makeStore('bio_only');

        $res = $this->get('/'.$store->slug);

        $res->assertStatus(302);
        $this->assertStringContainsString('/'.$store->slug.'/bio', $res->headers->get('Location'));
    }

    public function test_bio_first_redirects_on_first_visit_only(): void
    {
        [, $store] = $this->makeStore('bio_first');

        $this->get('/'.$store->slug)
            ->assertStatus(302)
            ->assertRedirectContains('/'.$store->slug.'/bio');

        // second visit in the same session (flag set) reaches the menu
        $res = $this->withSession(['bio_seen_'.$store->id => true])->get('/'.$store->slug);
        $this->assertNotRedirectedToBio($res);
    }

    public function test_bio_first_preserves_query_on_redirect(): void
    {
        [, $store] = $this->makeStore('bio_first');

        $res = $this->get('/'.$store->slug.'?utm_source=ig&foo=bar');

        $res->assertStatus(302);
        $loc = $res->headers->get('Location');
        $this->assertStringContainsString('/bio', $loc);
        $this->assertStringContainsString('utm_source=ig', $loc);
        $this->assertStringContainsString('foo=bar', $loc);
    }

    public function test_g2_qr_scan_bypasses_and_is_counted_under_bio_only(): void
    {
        [, $store] = $this->makeStore('bio_only');
        $qr = app_xor_encrypt($store->slug, 'qr-scan');

        $res = $this->get('/'.$store->slug.'?qr-id='.urlencode($qr));

        // reaches publicView: scan counted + redirected to the CLEAN menu, not /bio
        $this->assertNotRedirectedToBio($res);
        $this->assertSame(1, StoreView::where('store_id', $store->id)->count(), 'qr scan must be counted in publicView');
    }

    public function test_g2_qr_scan_bypasses_and_is_counted_under_bio_first(): void
    {
        [, $store] = $this->makeStore('bio_first');
        $qr = app_xor_encrypt($store->slug, 'qr-scan');

        $res = $this->get('/'.$store->slug.'?qr-id='.urlencode($qr));

        $this->assertNotRedirectedToBio($res);
        $this->assertSame(1, StoreView::where('store_id', $store->id)->count());
    }

    public function test_table_param_bypasses_interstitial(): void
    {
        [, $store] = $this->makeStore('bio_only');

        $res = $this->get('/'.$store->slug.'?table=7');

        $this->assertNotRedirectedToBio($res);
    }

    public function test_item_param_bypasses_interstitial(): void
    {
        [, $store] = $this->makeStore('bio_only');

        $res = $this->get('/'.$store->slug.'?item=42');

        $this->assertNotRedirectedToBio($res);
    }

    public function test_logged_in_owner_bypasses_interstitial(): void
    {
        [$owner, $store] = $this->makeStore('bio_only');

        $res = $this->actingAs($owner)->get('/'.$store->slug);

        $this->assertNotRedirectedToBio($res);
    }

    public function test_dark_store_has_zero_change(): void
    {
        // bio_only stored, but the feature is dark (no admin toggle / entitlement)
        [, $store] = $this->makeStore('bio_only');
        config(['settings.admin_allow_biolink' => false]);

        $res = $this->get('/'.$store->slug);

        $this->assertNotRedirectedToBio($res);
    }
}
