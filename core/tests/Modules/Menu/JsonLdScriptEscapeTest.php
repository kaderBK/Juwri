<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * WI-13-sec: inline JSON-LD must never let store/owner text close the
 * `<script type="application/ld+json">` element. `JSON_UNESCAPED_SLASHES`
 * alone turned a menu item named `</script><script>…` into stored XSS on the
 * storefront (same origin as the dashboards). Every JSON-LD body now goes
 * through json_ld(), which hex-escapes < > & ' ".
 */
class JsonLdScriptEscapeTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '</script><script>alert(1)</script>';

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

    public function test_json_ld_hex_escapes_markup_and_keeps_slashes_readable(): void
    {
        $out = json_ld(['name' => self::PAYLOAD, 'url' => 'https://example.test/a', 'q' => "it's \"x\" & <!--"]);

        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringNotContainsString('&', $out);
        $this->assertStringNotContainsString("'", $out);
        $this->assertStringContainsString('https://example.test/a', $out);
        $this->assertSame(['name' => self::PAYLOAD, 'url' => 'https://example.test/a', 'q' => "it's \"x\" & <!--"], json_decode($out, true));
    }

    public function test_storefront_with_a_malicious_menu_item_does_not_break_out_of_json_ld(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'xss-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Evil '.self::PAYLOAD, 'sub_title' => 'T', 'description' => self::PAYLOAD, 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '5551234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'orbit');
        StoreOption::updateStoreOption($store->id, 'menu_seo_jsonld', '1');   // dish names go into JSON-LD
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat '.self::PAYLOAD, 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => self::PAYLOAD, 'description' => self::PAYLOAD, 'price' => 10, 'active' => '1', 'position' => 1]);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('"@type":"MenuItem"', $html);
        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);

        // The JSON-LD block still parses and carries the dish name verbatim.
        $this->assertGreaterThanOrEqual(1, preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m));
        $names = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            $this->assertIsArray($data, 'every JSON-LD block must be valid JSON');
            array_walk_recursive($data, function ($v, $k) use (&$names) {
                if ($k === 'name') {
                    $names[] = $v;
                }
            });
        }
        $this->assertContains(self::PAYLOAD, $names);
    }

    /** Guard: no view prints json_encode(..., JSON_UNESCAPED_SLASHES) raw without JSON_HEX_TAG. */
    public function test_no_view_prints_unescaped_slashes_json_raw(): void
    {
        $offenders = [];
        $it = new \AppendIterator; // resources/views + the ee modules' Views (P1-13)
        foreach (ViewRoots::all() as $root) {
            $it->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)));
        }
        foreach ($it as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (! preg_match_all('/\{!!(.*?)!!\}/s', $src, $m)) {
                continue;
            }
            foreach ($m[1] as $expr) {
                if (str_contains($expr, 'json_encode') && str_contains($expr, 'JSON_UNESCAPED_SLASHES') && ! str_contains($expr, 'JSON_HEX_TAG')) {
                    $offenders[] = ViewRoots::relative($file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'use json_ld() for inline JSON: '.implode(', ', $offenders));
    }
}
