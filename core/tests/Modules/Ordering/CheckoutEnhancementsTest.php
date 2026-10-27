<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

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
use Tests\TestCase;

/**
 * F12: the shared checkout-enhancement panel ships scheduled_at / gift_card_code /
 * curbside inputs to themes that don't render them natively, gated per owner flag.
 * Verified on orbit (a keep-list theme that ships none of them).
 */
class CheckoutEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        ]);
    }

    private function store(array $options = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $r = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($r->id, 'store_template', 'orbit');
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);

        return $r;
    }

    /** With the owner flags on, the inputs render on a theme that lacks them (orbit). */
    public function test_inputs_render_when_flags_on(): void
    {
        $r = $this->store([
            'allow_order_scheduling' => '1',
            'gift_cards_enabled' => '1',
            'curbside_enabled' => '1',
        ]);

        $this->get(route('publicView', $r->slug))->assertOk()->assertSeeHtml('name="scheduled_at"')->assertSeeHtml('name="gift_card_code"')->assertSeeHtml('name="curbside"')->assertSeeHtml('mbce-card');
    }

    /** With every flag off (default), the panel emits nothing. */
    public function test_panel_absent_when_all_flags_off(): void
    {
        $r = $this->store();

        $this->get(route('publicView', $r->slug))->assertOk()->assertDontSeeHtml('mbce-card')->assertDontSeeHtml('mbCeCard');
    }

    /** Only the enabled field renders (gift cards only). */
    public function test_only_enabled_field_renders(): void
    {
        $r = $this->store(['gift_cards_enabled' => '1']);

        $this->get(route('publicView', $r->slug))->assertOk()->assertSeeHtml('name="gift_card_code"')->assertDontSeeHtml('name="scheduled_at"')->assertDontSeeHtml('name="curbside"');
    }
}
