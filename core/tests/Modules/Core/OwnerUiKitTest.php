<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Juwri\Core\Support\OwnerUi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner UI kit (assets/owner-ui + <x-ui.*>): reference page access, component
 * markup/accessibility contracts, the brand-contrast helper, and the CSS rules
 * the redesign depends on (tokens only, logical properties, transform/opacity
 * motion only).
 */
class OwnerUiKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, TrackOwnerScreenView::class]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function owner(array $attrs = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create(array_merge([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ], $attrs));
    }

    private function css(): string
    {
        $css = File::get(public_path('assets/owner-ui/components.css'));

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /* ── Reference page ─────────────────────────────────────────────────── */

    public function test_kit_page_renders_every_component_inside_the_dashboard_layout(): void
    {
        $this->assertSame('/dashboard/ui-kit', route('owner.ui.kit', [], false));

        $html = (string) $this->actingAs($this->owner())->get(route('owner.ui.kit'))->assertOk()->getContent();

        foreach ([
            'class="ui-btn', 'ui-btn--dark', 'ui-icon-btn__dot', 'role="switch"', 'ui-field--error',
            'aria-invalid="true"', 'class="ui-seg"', 'aria-pressed="true"', 'data-tone="success"',
            'ui-card--hero', 'ui-hero__number', 'class="ui-row"', 'class="ui-table"', 'data-label="Price"',
            'role="alert"', 'class="ui-empty"', 'aria-busy="true"', '<dialog id="kit-pause-sheet"',
            'data-ui-open="kit-pause-sheet"', 'data-ui-toast=',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "kit page is missing {$needle}");
        }
    }

    public function test_kit_page_is_hidden_outside_local_and_testing_for_owners(): void
    {
        $owner = $this->owner();
        $this->app['env'] = 'production';

        $this->actingAs($owner)->get(route('owner.ui.kit'))->assertNotFound();
    }

    public function test_kit_page_access_rule(): void
    {
        $owner = $this->owner();
        $admin = $this->owner(['user_type' => 'admin']);

        $this->assertTrue(OwnerUi::kitPageAllowed($owner));
        $this->assertFalse(OwnerUi::kitPageAllowed(null));

        $this->app['env'] = 'production';
        $this->assertFalse(OwnerUi::kitPageAllowed($owner));
        $this->assertTrue(OwnerUi::kitPageAllowed($admin));
        $this->assertFalse(OwnerUi::kitPageAllowed(null));
    }

    public function test_dashboard_head_loads_the_kit_and_contrast_colour(): void
    {
        $html = (string) $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('assets/owner-ui/tokens.css', $html);
        $this->assertStringContainsString('assets/owner-ui/components.css', $html);
        $this->assertStringContainsString('assets/owner-ui/owner-ui.js', $html);
        $this->assertMatchesRegularExpression('/:root\{--ui-on-accent:#(FFFFFF|17171C);\}/', $html);
        $this->assertStringNotContainsString('family=Alexandria', $html, 'Arabic display face must only load on RTL pages');
    }

    /* ── Contrast helper ────────────────────────────────────────────────── */

    public function test_on_accent_picks_readable_text_for_any_brand_colour(): void
    {
        $this->assertSame(OwnerUi::WHITE, OwnerUi::onAccent('#4F46E5'));
        $this->assertSame(OwnerUi::WHITE, OwnerUi::onAccent('#6366f1'));
        $this->assertSame(OwnerUi::WHITE, OwnerUi::onAccent('#17171C'));
        $this->assertSame(OwnerUi::WHITE, OwnerUi::onAccent('#0F766E'));
        $this->assertSame(OwnerUi::INK, OwnerUi::onAccent('#FFD400'));
        $this->assertSame(OwnerUi::INK, OwnerUi::onAccent('#B8F04A'));
        $this->assertSame(OwnerUi::INK, OwnerUi::onAccent('#FFFFFF'));
        $this->assertSame(OwnerUi::WHITE, OwnerUi::onAccent('not-a-colour'));
        $this->assertSame(OwnerUi::WHITE, OwnerUi::onAccent(null));
    }

    /* ── Component contracts ────────────────────────────────────────────── */

    public function test_button_renders_link_or_button_with_variant_classes(): void
    {
        $link = Blade::render('<x-ui.button variant="dark" size="sm" href="/x" icon="plus">Go</x-ui.button>');
        $this->assertMatchesRegularExpression('/<a href="\/x" class="ui-btn ui-btn--dark ui-btn--sm"/', $link);
        $this->assertStringContainsString('<svg', $link);

        $button = Blade::render('<x-ui.button type="submit" :busy="true" data-ui-busy>Save</x-ui.button>');
        $this->assertStringContainsString('<button type="submit" class="ui-btn" data-ui-busy', $button);
        $this->assertStringContainsString('aria-busy="true"', $button);

        $icon = Blade::render('<x-ui.icon-button label="Search" icon="search" />');
        $this->assertStringContainsString('aria-label="Search"', $icon);
    }

    public function test_switch_is_a_real_checkbox_with_switch_semantics(): void
    {
        $html = Blade::render('<x-ui.switch id="s1" name="accepting" :checked="true" label="Taking orders" description="Customers can order now" data-ui-autosubmit />');

        $this->assertStringContainsString('<label class="ui-switch-row" for="s1">', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch" id="s1" class="ui-switch__input"\s+name="accepting" value="1"\s+checked/', $html);
        $this->assertStringContainsString('aria-describedby="s1-desc"', $html);
        $this->assertStringContainsString('id="s1-desc"', $html);
        $this->assertStringContainsString('data-ui-autosubmit', $html);
    }

    public function test_field_shows_validation_error_and_wires_accessibility(): void
    {
        View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['price' => 'Enter a price above 0.'])));

        $html = Blade::render('<x-ui.field name="price" label="Price" type="number" prefix="SAR" help="ignored when erroring" />');

        $this->assertStringContainsString('class="ui-field ui-field--error', $html);
        $this->assertStringContainsString('<label class="ui-field__label" for="ui-f-price">Price</label>', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="ui-f-price-help"', $html);
        $this->assertStringContainsString('Enter a price above 0.', $html);
        $this->assertStringNotContainsString('ignored when erroring', $html);
        $this->assertStringContainsString('<span class="ui-field__prefix">SAR</span>', $html);

        View::share('errors', new ViewErrorBag);
        $select = Blade::render('<x-ui.field name="cat" label="Category" type="select" value="b" :options="[\'a\' => \'A\', \'b\' => \'B\']" />');
        $this->assertMatchesRegularExpression('/<option value="b"\s+selected/', $select);
    }

    public function test_count_chip_sheet_and_hero_figure_contracts(): void
    {
        $this->assertSame('', trim(Blade::render('<x-ui.count :value="0" />')));
        $this->assertStringContainsString('>99+<', Blade::render('<x-ui.count :value="150" />'));

        $this->assertStringContainsString('aria-current="true"', Blade::render('<x-ui.chip href="/p?cat=a" :active="true" :count="5">A</x-ui.chip>'));
        $this->assertStringContainsString('aria-pressed="false"', Blade::render('<x-ui.chip>B</x-ui.chip>'));

        $sheet = Blade::render('<x-ui.sheet id="p" title="Pause?" description="Customers see Back soon."><x-slot:footer><x-ui.button data-ui-close>Cancel</x-ui.button></x-slot:footer></x-ui.sheet>');
        $this->assertStringContainsString('<dialog id="p" class="ui-sheet" aria-labelledby="p-title"', $sheet);
        $this->assertStringContainsString('aria-describedby="p-desc"', $sheet);
        $this->assertStringContainsString('class="ui-sheet__foot"', $sheet);
        $this->assertStringNotContainsString('ui-sheet__body', $sheet, 'empty body slot renders no wrapper');

        $hero = Blade::render('<x-ui.hero-figure label="Sales today" value="4,862.50" unit="SAR" delta="+12.4%" />');
        $this->assertLessThan(strpos($hero, 'ui-hero__number'), strpos($hero, 'ui-hero__unit'), 'LTR: unit leads the number');

        $banner = Blade::render('<x-ui.banner tone="danger">Key expired.</x-ui.banner>');
        $this->assertStringContainsString('role="alert"', $banner);
    }

    public function test_components_have_no_inline_event_handlers(): void
    {
        foreach (File::files(resource_path('views/components/ui')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', File::get($file->getPathname()), $file->getFilename().' has an inline handler (CSP script-src-attr none)');
        }
    }

    /* ── CSS rules the redesign depends on ──────────────────────────────── */

    public function test_components_css_uses_tokens_only(): void
    {
        $css = $this->css();

        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, 'raw hex colour in components.css — add a --ui-* token');
        $this->assertDoesNotMatchRegularExpression('/\b(rgba?|hsla?)\(/', $css, 'raw rgb/hsl colour in components.css — add a --ui-* token');
    }

    public function test_components_css_uses_logical_properties(): void
    {
        $css = $this->css();

        $this->assertDoesNotMatchRegularExpression('/(?<![-\w])(margin|padding|border)-(left|right)\b/', $css);
        $this->assertDoesNotMatchRegularExpression('/(?<![-\w])(left|right)\s*:/', $css);
        $this->assertDoesNotMatchRegularExpression('/text-align:\s*(left|right)\b/', $css);
    }

    public function test_only_transform_and_opacity_animate(): void
    {
        $css = $this->css();

        preg_match_all('/transition:\s*([^;]+);/', $css, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $value) {
            foreach (explode(',', $value) as $part) {
                $prop = strtok(trim($part), ' ');
                $this->assertContains($prop, ['transform', 'opacity'], "transition on {$prop}");
            }
        }

        preg_match_all('/@keyframes\s+[\w-]+\s*\{((?:[^{}]*\{[^{}]*\})+)\s*\}/', $css, $frames);
        $this->assertNotEmpty($frames[1]);
        foreach ($frames[1] as $body) {
            preg_match_all('/([a-z-]+)\s*:/', $body, $props);
            foreach ($props[1] as $prop) {
                $this->assertContains($prop, ['transform', 'opacity', 'background-position'], "keyframes animate {$prop}");
            }
        }
    }

    public function test_tokens_define_dark_theme_and_reduced_motion(): void
    {
        $tokens = File::get(public_path('assets/owner-ui/tokens.css'));

        $this->assertStringContainsString(':root[data-theme="dark"]', $tokens);
        $this->assertStringContainsString('[dir="rtl"]', $tokens);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $tokens);

        preg_match_all('/var\((--ui-[\w-]+)/', $this->css(), $used);
        // Per-use layout knobs with fallbacks, set inline by the caller (not theme tokens).
        $local = ['--ui-gap', '--ui-grid-min'];
        foreach (array_diff(array_unique($used[1]), $local) as $token) {
            $this->assertStringContainsString($token.':', $tokens, "components.css uses undefined token {$token}");
        }
    }
}
