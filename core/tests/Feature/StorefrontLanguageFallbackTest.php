<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Controllers\User\StoreController;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A store's saved default_language can name a language the platform has since
 * DEACTIVATED — the live menu languages only keep the active ones. The
 * storefront then resolved no language at all and fell back to the platform
 * default instead of the store's first live menu language.
 */
class StorefrontLanguageFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // The storefront layout reads the platform settings ($settings->userlangsel …).
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

        // The visitor's language comes from that cookie (or the URL prefix) —
        // start without one, so the store's own saved default has to decide.
        unset($_COOKIE['Quick_user_lang_code']);
        config(['settings.include_language_code' => false]);

        Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'French', 'direction' => 'ltr', 'active' => 0]);
    }

    /**
     * A store offering [en, fr] whose saved default (fr) is deactivated.
     */
    private function makeStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'orbit');
        StoreOption::updateStoreOption($store->id, 'menu_languages', ['en', 'fr']);
        StoreOption::updateStoreOption($store->id, 'default_language', 'fr');

        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);

        return $store;
    }

    /** The public storefront renders in the store's first LIVE menu language. */
    public function test_storefront_renders_in_the_first_live_menu_language_when_the_saved_default_is_deactivated(): void
    {
        $store = $this->makeStore();

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertViewHas('default_menu_language', fn ($language) => $language instanceof Language && $language->code === 'en');
        $response->assertSessionHas('locale', 'en', 'the visitor is served in the resolved language');
    }

    /** The resolver itself falls back to the first live menu language. */
    public function test_the_language_resolver_falls_back_to_the_first_live_menu_language(): void
    {
        $store = $this->makeStore();

        [$languages, $current] = $this->resolveStorefrontLanguage($store, store_options($store->id));

        $this->assertCount(1, $languages, 'only the active language is served');
        $this->assertInstanceOf(Language::class, $current, 'a language resolved');
        $this->assertSame('en', $current->code);
    }

    /**
     * Call the private resolver the storefront uses.
     *
     * @return array{0: \Illuminate\Support\Collection<int, Language>, 1: Language|array{}}
     */
    private function resolveStorefrontLanguage(Store $store, object $options): array
    {
        $controller = new StoreController;
        $method = new \ReflectionMethod($controller, 'storefrontLanguage');
        $method->setAccessible(true);

        return $method->invoke($controller, $store, $options);
    }
}
