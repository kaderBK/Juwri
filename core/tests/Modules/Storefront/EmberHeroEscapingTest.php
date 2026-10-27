<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-M1: the ember hero echoed merchant-supplied `theme_setting()` through Blade's
 * unescaped `{!! !!}` — the only such site in the 17 kept templates — a stored XSS
 * on the anonymous storefront origin where diners hold a `customer` session. The
 * fix escapes with e() then restores only the two fixed tag shapes the shipped
 * default uses, so markup in the default still renders but injected HTML does not.
 */
class EmberHeroEscapingTest extends TestCase
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
        ]);
    }

    private function emberStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'allow_ordering' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'ember');

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1']);

        return $store;
    }

    private function setHero(Store $store, string $value): void
    {
        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => 'ember',
            'settings' => ['texts.featured_title' => $value],
        ]);
    }

    public function test_a_scripted_hero_override_is_escaped(): void
    {
        $store = $this->emberStore();
        $this->setHero($store, '<img src=x onerror="alert(1)">Pwn');

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;img src=x onerror', $html);
        $this->assertStringContainsString('Pwn', $html);
    }

    public function test_a_stored_copy_of_the_shipped_default_still_renders_its_markup(): void
    {
        $store = $this->emberStore();
        // Byte-identical to assets/store_templates/ember/theme.json's default — what
        // ThemeCustomizerService::defaults() writes on a store's first save.
        $this->setHero($store, 'Good Food<br><span class="hot">Good Mood!</span>');

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<span class="hot">Good Mood!</span>', $html);
        $this->assertStringNotContainsString('&lt;span class=&quot;hot&quot;&gt;', $html);
    }

    public function test_the_untouched_theme_default_still_renders_its_markup(): void
    {
        $store = $this->emberStore();

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('<span class="hot">', $html);
        $this->assertStringNotContainsString('&lt;span class=&quot;hot&quot;&gt;', $html);
    }

    public function test_no_kept_template_echoes_theme_setting_unescaped(): void
    {
        $templates = KeptTemplates::MODERN;

        foreach ($templates as $slug) {
            $path = resource_path("views/store_templates/{$slug}/index.blade.php");
            if (! is_file($path)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\{!!\s*theme_setting\s*\(/',
                (string) file_get_contents($path),
                "theme {$slug}: theme_setting() must never be echoed through {!! !!} — it is merchant-supplied"
            );
        }
    }

    public function test_the_hero_field_carries_an_operator_hint(): void
    {
        $fields = ThemeCustomizerService::fields(ThemeCustomizerService::schemaFor('ember'));

        $this->assertNotEmpty($fields['texts.featured_title']['help'] ?? '');
    }
}
