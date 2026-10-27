<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\StorefrontThemeService;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Juwri\Modules\Storefront\Services\Theme\ThemeAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-12: the alias map (HANDOFF-TO-JUWRI.md section 3). An old theme slug renders as a kit theme in a preset, saved
 * customizer values still win over the preset, nothing is deleted (owner Q3) and the D11 themes are untouched.
 *
 * Rows whose kit theme is not built yet (brew, aisle, basket, book have no Blade views until their own work items) resolve to
 * the OLD theme: the same assertions then hold the safe fallback, and flip to "renders the kit theme" by themselves when the
 * kit theme ships.
 */
class ThemeAliasMapTest extends TestCase
{
    use RefreshDatabase;

    /** HANDOFF section 3, verbatim (old slug => [theme, preset]) */
    private const HANDOFF = [
        'crave' => ['table', 'app'], 'feast' => ['table', 'delivery'], 'plate' => ['table', 'classic'], 'dough' => ['table', 'pizzeria'],
        'pizzaflow' => ['table', 'pizzeria'], 'soiree' => ['table', 'lounge'], 'minimal' => ['table', 'classic'], 'nebula' => ['table', 'app'],
        'pulse' => ['table', 'app'], 'pandabite' => ['table', 'delivery'],
        'picnic' => ['brew', 'matcha'], 'lime' => ['brew', 'matcha'], 'velora' => ['brew', 'bakery'], 'matcha' => ['brew', 'matcha'],
        'crumb' => ['brew', 'bakery'], 'scoop' => ['brew', 'dessert'], 'orbit' => ['brew', 'clean'],
        'zevoa' => ['basket', 'fresh'],
        'shelf' => ['aisle', 'clean'], 'maison' => ['aisle', 'editorial'], 'torque' => ['aisle', 'garage'], 'lumiere' => ['aisle', 'beauty'],
        'bookla' => ['book', 'calm'],
    ];

    /** D11 themes keep their code; pageview and folio are display modes */
    private const UNTOUCHED = ['aurora', 'velvet', 'ember', 'glow', 'vivid', 'carely', 'freshly', 'aurelia', 'pageview', 'folio'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['theme-successors.enabled' => true]);
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function rows(): array
    {
        $out = [];
        foreach (self::HANDOFF as $old => [$theme, $preset]) {
            $out[$old.' -> '.$theme.'/'.$preset] = [$old, $theme, $preset];
        }

        return $out;
    }

    private function built(string $theme): bool
    {
        return view()->exists('store_templates.'.$theme.'.index');
    }

    private function store(string $template): Store
    {
        $this->seed(ThemeShowcaseSeeder::class);
        $store = Store::where('slug', 'crave-demo')->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        ThemeSetting::where('store_id', $store->id)->delete();

        return $store;
    }

    public function test_the_shipped_map_is_exactly_the_handoff_table_and_names_real_presets(): void
    {
        $shipped = config('theme-successors.map');
        $this->assertSame(self::HANDOFF, array_map(fn ($r) => [$r[0], $r[1]], $shipped));
        $this->assertSame([], array_intersect(array_keys($shipped), self::UNTOUCHED), 'D11 themes and display modes are not aliased');

        foreach ($shipped as $old => [$theme, $preset]) {
            $this->assertContains($theme, KitThemes::SLUGS, "{$old} maps to a kit theme");
            $presets = array_column(ThemeCustomizerService::schemaFor($theme)['presets'] ?? [], 'id');
            $this->assertContains($preset, $presets, "{$old} -> {$theme}: preset '{$preset}' exists in the theme's theme.json");
        }
    }

    public function test_every_old_theme_keeps_its_files(): void
    {
        $repo = dirname(base_path());
        foreach ([...array_keys(self::HANDOFF), ...array_diff(self::UNTOUCHED, ['flipbook'])] as $slug) {
            $this->assertFileExists(base_path("resources/views/store_templates/{$slug}/index.blade.php"), "{$slug}: views kept (owner Q3)");
            $this->assertDirectoryExists($repo."/assets/store_templates/{$slug}", "{$slug}: assets kept (owner Q3)");
        }
    }

    public function test_the_d11_themes_and_display_modes_are_never_aliased(): void
    {
        foreach (self::UNTOUCHED as $slug) {
            $this->assertNull(ThemeAliases::target($slug), $slug);
            $this->assertSame($slug, ThemeAliases::effective($slug));
            $this->assertSame($slug, app(StorefrontThemeService::class)->resolve($slug), "{$slug} still resolves to itself");
        }
    }

    #[DataProvider('rows')]
    public function test_an_old_slug_renders_the_kit_theme_in_the_mapped_preset(string $old, string $theme, string $preset): void
    {
        $expected = $this->built($theme) ? $theme : $old;
        $this->assertSame($expected, app(StorefrontThemeService::class)->resolve($old), "{$old} resolves to {$expected}");
        $this->assertSame($expected, resolve_store_theme($old));

        if (! $this->built($theme)) {
            $this->assertNull(ThemeAliases::target($old), "{$theme} is not built: the old theme keeps rendering");

            return;
        }

        $schema = ThemeCustomizerService::schemaFor($theme);
        $presetValues = ThemeCustomizerService::presetValues($schema, $preset);
        $store = $this->store($old);

        $html = $this->get('/crave-demo')->assertOk()->getContent();
        $this->assertStringContainsString('data-theme-slug="'.$theme.'"', $html, "{$old} renders {$theme}");
        $this->assertStringContainsString('data-scheme="'.$presetValues['scheme.mode'].'"', $html, "{$preset} scheme");
        // a dark preset puts its dark role under the --tc-* names, a light or auto one its light role
        $primary = $presetValues['scheme.mode'] === 'dark' ? $presetValues['colors_dark.primary'] : $presetValues['colors.primary'];
        $this->assertStringContainsStringIgnoringCase('--tc-primary:'.$primary, str_replace(' ', '', $html), "{$preset} primary colour reaches the page");
        $this->assertSame($old, (string) store_options($store->id)->store_template, 'nothing is rewritten in the database');
    }

    public function test_values_saved_under_the_old_theme_still_override_the_preset(): void
    {
        $store = $this->store('crave');
        $presetValues = ThemeCustomizerService::presetValues(ThemeCustomizerService::schemaFor('table'), 'app');
        $this->assertNotSame('#123456', $presetValues['colors.primary']);

        ThemeSetting::create(['store_id' => $store->id, 'template' => 'crave', 'settings' => [
            'colors.primary' => '#123456',
            'scheme.mode' => 'light',
            'toggles.show_splash' => false,         // a crave-only key: dropped
            'sections.hero' => false,               // crave's own section toggle: dropped
            'layout.radius' => 'not-a-radius',      // refused by the kit theme's schema: dropped, the preset's value stands
        ]]);

        $values = ThemeAliases::values((int) $store->id, 'crave');
        $this->assertSame('#123456', $values['colors.primary'], 'the saved colour wins over the preset');
        $this->assertSame('light', $values['scheme.mode']);
        $this->assertSame($presetValues['colors.accent'], $values['colors.accent'], 'what was not saved comes from the preset');
        $this->assertSame($presetValues['layout.radius'], $values['layout.radius'], 'an invalid saved value never reaches the page');
        $this->assertArrayNotHasKey('toggles.show_splash', $values);
        $this->assertNotFalse($values['sections.hero'] ?? true, "crave's own section toggle is not carried over");

        $html = $this->get('/crave-demo')->assertOk()->getContent();
        $this->assertStringContainsString('data-theme-slug="table"', $html);
        $this->assertStringContainsString('data-scheme="light"', $html);
        $this->assertStringContainsStringIgnoringCase('--tc-primary:#123456', str_replace(' ', '', $html));
        $this->assertStringNotContainsStringIgnoringCase('--tc-primary:'.$presetValues['colors.primary'], str_replace(' ', '', $html));
    }

    public function test_a_row_saved_under_the_kit_theme_itself_wins_over_the_alias(): void
    {
        $store = $this->store('plate');
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'plate', 'settings' => ['colors.primary' => '#111111']]);
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'table', 'settings' => ['colors.primary' => '#222222']]);

        $this->assertSame('#222222', ThemeAliases::values((int) $store->id, 'plate')['colors.primary']);
    }

    public function test_the_alias_is_off_when_the_platform_switch_is_off(): void
    {
        config(['theme-successors.enabled' => false]);
        $this->assertNull(ThemeAliases::target('crave'));
        $this->assertSame('crave', app(StorefrontThemeService::class)->resolve('crave'));

        $store = $this->store('crave');
        $this->get('/crave-demo')->assertOk();
        $this->assertSame('crave', (string) store_options($store->id)->store_template);
    }

    public function test_an_aliased_store_renders_and_takes_an_order_on_each_built_kit_theme(): void
    {
        $tested = [];
        foreach (KitThemes::SLUGS as $theme) {
            if (! $this->built($theme)) {
                continue;
            }
            $old = array_key_first(array_filter(self::HANDOFF, fn ($row) => $row[0] === $theme));
            $store = $this->store($old);

            $html = $this->get('/crave-demo')->assertOk()->getContent();
            $this->assertStringContainsString('data-theme-slug="'.$theme.'"', $html, "{$old} -> {$theme} renders");

            $s = new ViewModelScenario($store, $theme);
            $cart = $s->cart();
            $quote = $s->build('en', 'cart', ['cart' => $cart])['page']['cart'];
            $order = $s->place($cart);
            $this->assertSame((int) $order->fresh()->grand_total_minor, $quote['total']['minor'], "{$old} -> {$theme}: the order is charged the quoted total");
            $this->assertSame((int) $store->id, (int) $order->store_id);
            $tested[] = $theme;
        }
        $this->assertContains('table', $tested, 'table is built: its aliased store was exercised');
    }

    /** P3-07: the seven old cafe themes now render `brew` (switched on here; the platform default is OFF until the P3-15 sweep) */
    public function test_the_brew_rows_render_brew_with_their_presets_layout_and_the_store_still_takes_an_order(): void
    {
        $expect = [
            'matcha' => ['scheme' => 'light', 'card' => 'grid', 'ratio' => null],
            'picnic' => ['scheme' => 'light', 'card' => 'grid', 'ratio' => null],
            'lime' => ['scheme' => 'light', 'card' => 'grid', 'ratio' => null],
            'velora' => ['scheme' => 'light', 'card' => 'grid', 'ratio' => null],
            'crumb' => ['scheme' => 'light', 'card' => 'grid', 'ratio' => null],
            'scoop' => ['scheme' => 'dark', 'card' => 'grid', 'ratio' => '4:3'],   // dessert
            'orbit' => ['scheme' => 'light', 'card' => 'list', 'ratio' => null],   // clean: list cards
        ];
        foreach ($expect as $old => $want) {
            $store = $this->store($old);
            $html = $this->get('/crave-demo')->assertOk()->getContent();
            $this->assertStringContainsString('data-theme-slug="brew"', $html, "{$old} renders brew");
            $this->assertStringContainsString('data-scheme="'.$want['scheme'].'"', $html, "{$old} scheme");
            $this->assertStringContainsString('data-card="'.$want['card'].'"', $html, "{$old} card layout from its preset");
            if ($want['ratio'] === null) {
                $this->assertStringNotContainsString('data-ratio=', $html, "{$old}");
            } else {
                $this->assertStringContainsString('data-ratio="'.$want['ratio'].'"', $html, "{$old} image shape from its preset");
            }
            $this->assertSame($old, (string) store_options($store->id)->store_template, 'the saved theme is not rewritten');
        }
        $this->assertEqualsCanonicalizing(array_keys($expect), array_keys(array_filter(self::HANDOFF, fn ($row) => $row[0] === 'brew')), 'all seven brew rows were exercised');
    }

    public function test_the_platform_default_is_off_until_the_e2e_sweep(): void
    {
        $this->assertStringContainsString("env('JUWRI_THEME_ALIASES', false)", (string) file_get_contents(config_path('theme-successors.php')), 'P3-15 gate: aliases are OFF unless the environment turns them on');
    }

    public function test_an_unbuilt_kit_theme_leaves_its_aliased_stores_on_the_old_theme_that_still_works(): void
    {
        foreach (['brew' => 'matcha', 'aisle' => 'shelf', 'basket' => 'zevoa', 'book' => 'bookla'] as $theme => $old) {
            if ($this->built($theme)) {
                continue; // it shipped: the per-row test above holds the kit render
            }
            $this->assertSame($old, resolve_store_theme($old), "{$old} keeps rendering while {$theme} is not built");
            $this->assertFileExists(base_path("resources/views/store_templates/{$old}/index.blade.php"));
        }
        $this->assertTrue(true);
    }
}
