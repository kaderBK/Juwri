<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R5-1 (D3) — the shared storefront announcement banner.
 *
 * The fleet bulk op writes `store_announcement` + `store_announcement_active`,
 * but until this partial no theme read them. The banner is included once in the
 * shared store_templates layout so every theme renders it, and it is gated
 * STRICTLY server-side: the text reaches the page only when the store's own
 * announcement is active AND non-empty. This pins both halves of that gate
 * through a real HTTP storefront render (not a Blade-direct include), so the
 * controller contract that supplies `$store`/options is exercised too.
 */
class StorefrontAnnouncementBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        /* ViewServiceProvider only shares $settings when APP_INSTALLED is set and
           phpunit.xml leaves it unset, so a storefront render dies on an undefined
           variable. Same stub the other storefront suites use. */
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
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /**
     * @param  array<string,string>  $options  extra store_options to set on the store
     */
    private function store(array $options = [], string $theme = 'crave'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        foreach ($options as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1',
        ]);

        return $store;
    }

    public function test_an_active_announcement_renders_on_the_storefront(): void
    {
        $store = $this->store([
            'store_announcement' => 'Closed for Eid',
            'store_announcement_active' => '1',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbAnnounce"', $html);
        $this->assertStringContainsString('Closed for Eid', $html);
    }

    /** Inactive → the banner (and its text) must not reach the page at all. */
    public function test_an_inactive_announcement_does_not_render(): void
    {
        $store = $this->store([
            'store_announcement' => 'Closed for Eid',
            'store_announcement_active' => '0',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('id="mbAnnounce"', $html);
        $this->assertStringNotContainsString('Closed for Eid', $html);
    }

    /** Active flag but empty text is not an announcement — nothing renders. */
    public function test_an_active_but_empty_announcement_does_not_render(): void
    {
        $store = $this->store([
            'store_announcement' => '   ',
            'store_announcement_active' => '1',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('id="mbAnnounce"', $html);
    }

    /** A store that never set an announcement gets no banner markup. */
    public function test_a_store_with_no_announcement_option_renders_nothing(): void
    {
        $store = $this->store();

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('id="mbAnnounce"', $html);
    }

    /** Included in the shared layout, so every retained modern theme gets it. */
    public function test_the_banner_renders_on_every_retained_template(): void
    {
        foreach (KeptTemplates::MODERN as $theme) {
            $store = $this->store([
                'store_announcement' => 'Holiday hours today',
                'store_announcement_active' => '1',
            ], $theme);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();
            $this->assertStringContainsString('id="mbAnnounce"', $html, "$theme should render the announcement banner");
            $this->assertStringContainsString('Holiday hours today', $html, "$theme should render the announcement text");
        }
    }
}
