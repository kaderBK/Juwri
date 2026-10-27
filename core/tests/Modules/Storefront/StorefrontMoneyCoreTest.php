<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-M16: one shared storefront money core (assets/store_templates/_shared/js/mb-money.js)
 * across all 17 kept templates. Pins that it is loaded once before the theme bundle,
 * that every theme delegates to it, that none still rolls its own rounding, and that
 * its output matches the server (Order::computeTax) on a server-generated fixture.
 */
class StorefrontMoneyCoreTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const THEMES = KeptTemplates::MODERN;

    private function themeSource(string $slug): string
    {
        return (string) file_get_contents(base_path('../assets/store_templates/'.$slug.'/js/script.js'));
    }

    public function test_the_layout_loads_the_money_core_exactly_once_before_the_theme_bundle(): void
    {
        $src = (string) file_get_contents(resource_path('views/store_templates/layout.blade.php'));

        // The <script src> is emitted once; the path also appears in the ?v= cache-buster's
        // public_path(), so count the asset() call, not every mention.
        $this->assertSame(1, substr_count($src, "asset('assets/store_templates/_shared/js/mb-money.js')"));
        $this->assertLessThan(
            strpos($src, '/js/script.js?ver='),
            strpos($src, '_shared/js/mb-money.js'),
            'The money core must be emitted BEFORE the theme bundle.'
        );
    }

    public function test_the_money_core_reproduces_the_servers_rounding_idiom(): void
    {
        $src = (string) file_get_contents(base_path('../assets/store_templates/_shared/js/mb-money.js'));

        $this->assertStringContainsString('toPrecision(15)', $src,
            'PHP round() pre-rounds to 15 significant digits; Math.round(4.10*0.15*100)/100 is a cent low without it.');
    }

    public function test_every_retained_theme_delegates_its_money_math_to_the_core(): void
    {
        $offenders = [];
        foreach (self::THEMES as $slug) {
            if (! str_contains($this->themeSource($slug), 'mbMoney.')) {
                $offenders[] = $slug;
            }
        }

        $this->assertSame([], $offenders, 'These themes do not delegate to window.mbMoney: '.implode(', ', $offenders));
    }

    public function test_no_retained_theme_still_rolls_its_own_rounding(): void
    {
        $offenders = [];
        foreach (self::THEMES as $slug) {
            $src = $this->themeSource($slug);
            if (preg_match('/Math\.round\([^)]*Math\.pow\(10/', $src) || str_contains($src, 'Number.EPSILON')) {
                $offenders[] = $slug;
            }
        }

        $this->assertSame([], $offenders, 'These themes still roll their own rounding: '.implode(', ', $offenders));
    }

    public function test_the_parity_fixture_matches_the_server(): void
    {
        $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/money-core-cases.json')), true);
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $stub = (object) [
                'restaurant_taxx_charge' => $row['rate'],
                'restaurant_tax_type' => $row['type'],
                'currency_code' => $row['currency_code'] ?? null,
            ];
            $this->assertEqualsWithDelta(
                (float) $row['expected'],
                Order::computeTax((float) $row['subtotal'], $stub),
                1e-9,
                'Fixture row diverged from Order::computeTax: '.json_encode($row)
            );
        }
    }

    public function test_the_dashboard_ordering_pane_shows_a_tax_preview(): void
    {
        app()->setLocale('en');
        $settings = new class(['theme_color' => '#111', 'site_title' => 'M', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
        ]);

        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Tax Store', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '15');
        StoreOption::updateStoreOption($r->id, 'restaurant_tax_type', 'percentage');

        $res = $this->actingAs($owner)->get(route('stores.edit', $r->id))->assertOk();

        $res->assertSee('Tax preview');
        // computeTax(100, 15%) = 15.00 — the number the storefront now also quotes.
        $res->assertSee('15.00');
    }
}
