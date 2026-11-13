<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Splash v2 — background / text colour / frequency / CTA fields on the shared
 * splash fragment, optional ('' = unset) colours in the validator, and
 * locale-map support for the splash text fields (the editor already offered
 * language tabs there in localized schemas; the map now validates and
 * resolves like texts.*).
 */
class SplashV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Storefront renders need the $settings composer stub + the Installed
        // middleware bypassed — same arrangement as SectionedThemeTest.
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

    public function test_the_fragment_exposes_the_v2_fields(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');
        $ids = collect($schema['settings'] ?? [])->flatMap(fn ($g) => collect($g['fields'] ?? [])->pluck('id'));

        foreach (['splash.background', 'splash.background_image', 'splash.text_color', 'splash.frequency', 'splash.cta_label'] as $id) {
            $this->assertTrue($ids->contains($id), "missing $id");
        }
    }

    public function test_an_unset_colour_validates_and_a_bad_one_does_not(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');

        $this->assertSame([], ThemeCustomizerService::validate($schema, [
            'splash.background' => '',
            'splash.text_color' => '#FFEE00',
        ]));

        $errors = ThemeCustomizerService::validate($schema, ['splash.background' => 'red']);
        $this->assertArrayHasKey('splash.background', $errors);

        // The existing required colour roles still reject empties' cousins —
        // a malformed hex is refused wherever it appears.
        $errors = ThemeCustomizerService::validate($schema, ['colors.primary' => 'not-a-colour']);
        $this->assertArrayHasKey('colors.primary', $errors);
    }

    public function test_the_frequency_select_is_enforced(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');

        $this->assertSame([], ThemeCustomizerService::validate($schema, ['splash.frequency' => 'daily']));
        $this->assertArrayHasKey('splash.frequency', ThemeCustomizerService::validate($schema, [
            'splash.frequency' => 'hourly',
        ]));
    }

    public function test_the_v21_selects_are_exposed_and_enforced(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');
        $ids = collect($schema['settings'] ?? [])->flatMap(fn ($g) => collect($g['fields'] ?? [])->pluck('id'));

        foreach (['splash.animation', 'splash.logo_size', 'splash.logo_shape'] as $id) {
            $this->assertTrue($ids->contains($id), "missing $id");
        }

        $this->assertSame([], ThemeCustomizerService::validate($schema, [
            'splash.animation' => 'rise',
            'splash.logo_size' => 'l',
            'splash.logo_shape' => 'round',
        ]));
        $this->assertArrayHasKey('splash.animation', ThemeCustomizerService::validate($schema, ['splash.animation' => 'spin']));
        $this->assertArrayHasKey('splash.logo_size', ThemeCustomizerService::validate($schema, ['splash.logo_size' => 'xl']));
    }

    public function test_the_shared_layer_renders_animation_and_logo_variants(): void
    {
        $store = $this->store([
            'splash.enabled' => true,
            'splash.style' => 'shared',
            'splash.animation' => 'rise',
            'splash.logo_size' => 'l',
            'splash.logo_shape' => 'round',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('mb-splash--anim-rise', $html);
        $this->assertStringContainsString('--mb-splash-logo:160px', $html);
        $this->assertStringContainsString('--mb-splash-logo-r:50%', $html);
    }

    public function test_unset_v21_fields_render_the_pop_default(): void
    {
        $store = $this->store([
            'splash.enabled' => true,
            'splash.style' => 'shared',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('mb-splash--anim-pop', $html);
        $this->assertStringContainsString('--mb-splash-logo:120px', $html);
        $this->assertStringContainsString('--mb-splash-logo-r:24px', $html);
    }

    public function test_the_validator_accepts_a_locale_map_for_the_splash_texts(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');
        $this->assertTrue($schema['texts']['localized'] ?? false, 'crave is a localized schema');

        $this->assertSame([], ThemeCustomizerService::validate($schema, [
            'splash.title' => ['en' => 'Welcome', 'ar' => 'أهلاً'],
            'splash.subtitle' => ['en' => 'Order in seconds'],
            'splash.cta_label' => ['en' => 'View menu', 'ar' => 'شاهد القائمة'],
        ]));

        $this->assertArrayHasKey('splash.title', ThemeCustomizerService::validate($schema, [
            'splash.title' => ['en' => ['nested' => 'no']],
        ]));
    }

    public function test_theme_setting_resolves_a_splash_locale_map(): void
    {
        $store = $this->store(['splash.title' => ['en' => 'Welcome', 'ar' => 'أهلاً']]);

        App::setLocale('en');
        $this->assertSame('Welcome', theme_setting($store->id, 'splash.title', 'FB'));

        App::setLocale('ar');
        $this->assertSame('أهلاً', theme_setting($store->id, 'splash.title', 'FB'));
    }

    public function test_a_plain_splash_title_string_still_works(): void
    {
        $store = $this->store(['splash.title' => 'Just a string']);

        App::setLocale('ar');
        $this->assertSame('Just a string', theme_setting($store->id, 'splash.title', 'FB'));
    }

    public function test_the_shared_layer_renders_the_cta_and_background(): void
    {
        $store = $this->store([
            'splash.enabled' => true,
            'splash.style' => 'shared',
            'splash.cta_label' => 'View the menu',
            'splash.background' => '#123456',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbSplashCta"', $html);
        $this->assertStringContainsString('View the menu', $html);
        $this->assertStringContainsString('background:#123456', $html);
    }

    public function test_an_unset_v2_field_set_renders_the_original_shared_layer(): void
    {
        $store = $this->store([
            'splash.enabled' => true,
            'splash.style' => 'shared',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbSplash"', $html);
        // The gate script always names the id — assert on the ELEMENT.
        $this->assertStringNotContainsString('id="mbSplashCta"', $html);
        // The stylesheet always names the class — assert on the ELEMENT.
        $this->assertStringNotContainsString('class="mb-splash__bgimg"', $html);
    }

    private function store(array $settings): Store
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'crave', 'settings' => $settings]);

        return $store;
    }
}
