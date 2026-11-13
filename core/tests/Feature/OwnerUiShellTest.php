<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner dashboard redesign — skin layer + mobile shell (assets/owner-ui/skin.css,
 * shell.css, partials/mobile-tabbar): kill switches, the phone tab bar built from
 * OwnerApp::tabsFor(), the in-app (Hotwire Native) chrome rules, and the same CSS
 * guards the kit follows.
 */
class OwnerUiShellTest extends TestCase
{
    use RefreshDatabase;

    private const APP_UA = 'Mozilla/5.0 (iPhone) JuwriApp/1.0.0 Hotwire Native iOS';

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
        config(['owner_ui.skin' => true, 'owner_ui.mobile_tabbar' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function ownerWithStore(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
        Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $owner;
    }

    private function dashboard(User $owner, array $headers = []): string
    {
        return (string) $this->actingAs($owner)->withHeaders($headers)->get(route('dashboard'))->assertOk()->getContent();
    }

    public function test_skin_class_and_stylesheets_follow_the_kill_switch(): void
    {
        $owner = $this->ownerWithStore();

        $on = $this->dashboard($owner);
        $this->assertMatchesRegularExpression('/<html[^>]*class="[^"]*\bui-skin\b/', $on);
        $this->assertStringContainsString('assets/owner-ui/skin.css', $on);
        $this->assertStringContainsString('assets/owner-ui/shell.css', $on);

        config(['owner_ui.skin' => false]);
        $off = $this->dashboard($owner);
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\bui-skin\b/', $off);
        $this->assertStringNotContainsString('assets/owner-ui/skin.css', $off);
        $this->assertStringContainsString('assets/owner-ui/shell.css', $off, 'shell (in-app chrome) is independent of the skin flag');
    }

    public function test_phone_tab_bar_uses_the_native_app_tabs(): void
    {
        $html = $this->dashboard($this->ownerWithStore());

        $this->assertStringContainsString('<nav class="ui-tabbar"', $html);
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bui-has-tabbar\b/', $html);
        preg_match('/<nav class="ui-tabbar".*?<\/nav>/s', $html, $m);
        $bar = $m[0] ?? '';
        $this->assertSame(5, substr_count($bar, 'class="ui-tabbar__item"'), 'five tabs: home, work board, catalogue, reports, more');
        $this->assertStringContainsString('data-ui-menu', $bar, '"More" opens the sidebar drawer');
        $this->assertMatchesRegularExpression('/href="[^"]*\/dashboard[^"]*" class="ui-tabbar__item"\s+aria-current="page"/', $bar, 'Home is current on the dashboard');
    }

    public function test_tab_bar_is_not_rendered_in_the_native_app_or_when_switched_off(): void
    {
        $owner = $this->ownerWithStore();

        $inApp = $this->dashboard($owner, ['User-Agent' => self::APP_UA]);
        $this->assertStringContainsString('data-owner-app="1"', $inApp);
        $this->assertStringNotContainsString('<nav class="ui-tabbar"', $inApp, 'the native shell draws its own tab bar');

        config(['owner_ui.mobile_tabbar' => false]);
        $off = $this->dashboard($owner);
        $this->assertStringNotContainsString('<nav class="ui-tabbar"', $off);
        $this->assertDoesNotMatchRegularExpression('/<body class="[^"]*\bui-has-tabbar\b/', $off);
    }

    public function test_in_app_rules_hide_web_chrome(): void
    {
        $shell = File::get(public_path('assets/owner-ui/shell.css'));

        foreach (['.app-header', '#appMenubar', '.ui-tabbar', '[data-web-only]'] as $selector) {
            $this->assertStringContainsString('html[data-owner-app="1"] '.$selector, $shell);
        }
    }

    public function test_skin_and_shell_css_follow_the_kit_rules(): void
    {
        $tokens = File::get(public_path('assets/owner-ui/tokens.css'));

        foreach (['skin.css', 'shell.css', 'screens.css'] as $name) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', File::get(public_path('assets/owner-ui/'.$name)));

            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, "{$name}: raw hex colour");
            $this->assertDoesNotMatchRegularExpression('/\b(rgba?|hsla?)\(/', $css, "{$name}: raw rgb/hsl colour");
            $this->assertDoesNotMatchRegularExpression('/(?<![-\w])(margin|padding|border)-(left|right)\b/', $css, "{$name}: physical property");
            $this->assertDoesNotMatchRegularExpression('/(?<![-\w])(left|right)\s*:/', $css, "{$name}: physical offset");

            preg_match_all('/transition:\s*([^;]+);/', $css, $t);
            foreach ($t[1] as $value) {
                foreach (explode(',', $value) as $part) {
                    $this->assertContains(strtok(trim($part), ' '), ['transform', 'opacity', 'none'], "{$name}: transition on non-transform/opacity");
                }
            }

            preg_match_all('/@keyframes[^{]+\{((?:[^{}]*\{[^}]*\})*)\s*\}/', $css, $frames);
            foreach ($frames[1] as $body) {
                preg_match_all('/([a-z-]+)\s*:/', $body, $props);
                foreach ($props[1] as $prop) {
                    $this->assertContains($prop, ['transform', 'opacity'], "{$name}: keyframes animate {$prop}");
                }
            }

            preg_match_all('/var\((--ui-[\w-]+)/', $css, $used);
            foreach (array_unique($used[1]) as $token) {
                $this->assertStringContainsString($token.':', $tokens, "{$name} uses undefined token {$token}");
            }
        }
    }
}
