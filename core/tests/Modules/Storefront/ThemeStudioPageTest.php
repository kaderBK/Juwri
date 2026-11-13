<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio editor page (lane C2): the Blade view + its two assets.
 *
 * The real route belongs to the backend lane, so the view is rendered through a
 * test-only route inside the dashboard layout with a fixture $studio array
 * shaped like rev/theme-studio/EDITOR-CONTRACT.md.
 */
class ThemeStudioPageTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'owner.design.studio';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'العربية', 'direction' => 'rtl', 'active' => 1]);
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

        Route::middleware('web')->get('/_test/theme-studio', function () {
            app()->setLocale((string) request('lang', 'en'));
            $studio = $this->fixture(request()->boolean('picker'));

            return view(self::VIEW, ['studio' => $studio]);
        });
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
    }

    /** @return array<string,mixed> */
    private function fixture(bool $picker = false): array
    {
        $base = 'https://example.test/dashboard/theme-studio';

        return [
            'store' => [
                'id' => 999001, 'slug' => 'burger-house',
                // Hostile title: must never break out of the JSON data block.
                'title' => 'Burger </script><script>window.pwned=1</script> & "Co"',
                'store_type' => 'restaurant',
                'languages' => [
                    ['code' => 'ar', 'name' => 'العربية', 'dir' => 'rtl'],
                    ['code' => 'en', 'name' => 'English', 'dir' => 'ltr'],
                ],
                'default_language' => 'ar',
            ],
            'stores' => [['id' => 999001, 'title' => 'Burger House'], ['id' => 999002, 'title' => 'Second']],
            'theme' => $picker ? null : ['template' => 'mp:studio-bistro@1', 'slug' => 'studio-bistro', 'version' => '1', 'name' => 'Bistro', 'is_studio' => true],
            'themes' => [
                ['slug' => 'studio-bistro', 'name' => 'Bistro', 'vertical_groups' => ['food'], 'thumb' => null],
                ['slug' => 'studio-market', 'name' => 'Market', 'vertical_groups' => ['goods'], 'thumb' => null],
            ],
            'recipes' => [['id' => 'restaurant/classic', 'name' => 'Classic restaurant', 'vertical' => 'restaurant']],
            'caps' => ['sections_add' => true, 'sections_remove' => true, 'sections_reorder' => true, 'blocks_edit' => true, 'tokens' => true, 'fonts' => true, 'custom_block' => false, 'ai_generate' => false],
            'limits' => ['max_sections' => 25, 'max_blocks' => 50],
            'urls' => [
                'state' => $base.'/state?store=999001',
                'draft' => $base.'/draft?store=999001',
                'section' => $base.'/section/__SID__?store=999001',
                'publish' => $base.'/publish?store=999001',
                'discard' => $base.'/discard?store=999001',
                'versions' => $base.'/versions?store=999001',
                'revert' => $base.'/revert/__ID__?store=999001',
                'media' => $base.'/media?store=999001',
                'start' => $base.'/start?store=999001',
                'preview' => 'https://example.test/burger-house?preview_theme=1&studio=1',
            ],
            'csrf' => 'csrf-token-fixture',
            'i18n' => ['Publish' => 'Go live'],
        ];
    }

    private function page(string $query = ''): string
    {
        return (string) $this->actingAs($this->owner())
            ->get('/_test/theme-studio'.($query !== '' ? '?'.$query : ''))
            ->assertOk()
            ->getContent();
    }

    /** @return array<string,mixed> */
    private function config(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="theme-studio-config">(.*?)</script>#s', $html, $m), 'config block missing');
        $cfg = json_decode($m[1], true);
        $this->assertIsArray($cfg, 'config block is not valid JSON');

        return $cfg;
    }

    public function test_editor_renders_inside_the_dashboard_layout_with_the_config_block(): void
    {
        $html = $this->page();
        $cfg = $this->config($html);

        // Contract keys pass through untouched.
        $this->assertSame('mp:studio-bistro@1', $cfg['theme']['template']);
        $this->assertSame('https://example.test/dashboard/theme-studio/section/__SID__?store=999001', $cfg['urls']['section']);
        $this->assertSame(['max_sections' => 25, 'max_blocks' => 50], $cfg['limits']);
        $this->assertFalse($cfg['caps']['custom_block']);
        $this->assertSame('csrf-token-fixture', $cfg['csrf']);
        // The controller's wording wins over the view's own strings.
        $this->assertSame('Go live', $cfg['i18n']['Publish']);
        $this->assertSame('Undo', $cfg['i18n']['Undo']);
        // UI-only additions.
        $this->assertSame('food', $cfg['ui']['group']);
        $this->assertSame(['category' => [], 'item_list' => [], 'branch' => []], $cfg['ui']['pickers']);

        foreach ([
            'id="theme-studio"', 'data-mode="editor"', 'data-ts-frame', 'data-ts-publish', 'data-ts-undo', 'data-ts-redo',
            'name="ts-device"', 'name="ts-lang"', 'value="ar"', 'data-ts-history', 'data-ts-preview', 'data-ts-store',
            '<dialog id="ts-sheet-left"', '<dialog id="ts-sheet-right"', '<dialog id="ts-gallery"', '<dialog id="ts-history"',
            'class="ts-mobilebar"', 'role="tablist"', 'aria-live="polite"', 'assets/theme-studio/studio.css',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "editor page is missing {$needle}");
        }
    }

    public function test_hostile_store_title_cannot_break_out_of_the_json_block(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('<script>window.pwned=1</script>', $html);
        $cfg = $this->config($html);
        $this->assertSame('Burger </script><script>window.pwned=1</script> & "Co"', $cfg['store']['title']);
    }

    public function test_editor_script_is_nonced_and_every_page_script_carries_a_nonce_or_is_data(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('#<script src="[^"]*assets/theme-studio/studio\.js\?v=\d+" nonce="[^"]+" defer></script>#', $html);

        // Only the JSON data block (not executed) may lack a nonce in this view's output.
        $view = File::get(resource_path('views/owner/design/studio.blade.php'));
        preg_match_all('#<script\b[^>]*>#', $view, $tags);
        foreach ($tags[0] as $tag) {
            $this->assertTrue(
                str_contains($tag, 'nonce="{{ csp_nonce() }}"') || str_contains($tag, 'type="application/json"'),
                "script tag without a nonce: {$tag}"
            );
        }
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|input|submit|load)\s*=/i', $view, 'no inline event handlers (CSP)');
    }

    public function test_editor_mirrors_for_an_arabic_dashboard(): void
    {
        $rtl = $this->page('lang=ar');
        $this->assertMatchesRegularExpression('#<div class="ts-app" id="theme-studio" dir="rtl"#', $rtl);
        $this->assertSame('rtl', $this->config($rtl)['ui']['dir']);

        $ltr = $this->page('lang=en');
        $this->assertMatchesRegularExpression('#<div class="ts-app" id="theme-studio" dir="ltr"#', $ltr);
    }

    public function test_theme_picker_shows_when_the_store_is_not_on_a_studio_theme(): void
    {
        $html = $this->page('picker=1');

        $this->assertStringContainsString('data-mode="picker"', $html);
        $this->assertStringContainsString('data-ts-start-theme="studio-bistro"', $html);
        $this->assertStringContainsString('data-ts-start-recipe="restaurant/classic"', $html);
        $this->assertStringNotContainsString('data-ts-frame', $html);
        // Food theme + restaurant recipe recommended for a restaurant; goods theme not.
        $this->assertSame(2, substr_count($html, 'class="ui-tag ts-card__tag"'));
        $this->assertNull($this->config($html)['theme']);
    }

    public function test_assets_use_no_native_dialogs_and_only_logical_css(): void
    {
        foreach (['studio.js', 'studio-bridge.js'] as $file) {
            $js = File::get(base_path('../assets/theme-studio/'.$file));
            $js = preg_replace('#/\*.*?\*/#s', '', $js);
            $js = preg_replace('#(^|[^:])//[^\n]*#', '$1', $js);
            $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z.])(alert|confirm|prompt)\s*\(/', $js, "{$file} calls a native dialog");
            $this->assertDoesNotMatchRegularExpression('/\beval\s*\(|new Function\s*\(/', $js, "{$file} evaluates code");
        }

        // The editor chrome mirrors in RTL: no physical margin/padding/float/text-align.
        $css = preg_replace('#/\*.*?\*/#s', '', File::get(base_path('../assets/theme-studio/studio.css')));
        $this->assertDoesNotMatchRegularExpression('/(margin|padding)-(left|right)\s*:|float\s*:\s*(left|right)|text-align\s*:\s*(left|right)/', $css);
    }

    public function test_bridge_checks_origin_and_source_and_keeps_its_chrome_in_a_shadow_root(): void
    {
        $js = File::get(base_path('../assets/theme-studio/studio-bridge.js'));
        $this->assertStringContainsString('e.source !== window.parent || e.origin !== location.origin', $js);
        $this->assertStringContainsString('postMessage(msg, location.origin)', $js);
        $this->assertStringContainsString("createElement('mb-studio-ui')", $js);
        $this->assertStringContainsString('attachShadow', $js);

        $editor = File::get(base_path('../assets/theme-studio/studio.js'));
        $this->assertStringContainsString('e.source !== els.frame.contentWindow || e.origin !== location.origin', $editor);
        foreach (['st:init', 'st:select', 'st:replace', 'st:vars', 'st:scrollTo', 'st:reload', 'st:ready', 'st:hover', 'st:action', 'st:text'] as $type) {
            $this->assertStringContainsString("'{$type}'", $editor.$js, "bridge message {$type} is not handled");
        }
    }

    public function test_js_assets_parse(): void
    {
        if (! function_exists('shell_exec') || ! function_exists('exec')) {
            $this->markTestSkipped('exec functions are disabled on this host');
        }
        $node = $this->nodeBinary();
        if ($node === null) {
            $this->markTestSkipped('node is not installed');
        }
        foreach (['studio.js', 'studio-bridge.js'] as $file) {
            $path = realpath(base_path('../assets/theme-studio/'.$file));
            exec(escapeshellarg($node).' --check '.escapeshellarg((string) $path).' 2>&1', $out, $code);
            $this->assertSame(0, $code, $file.': '.implode("\n", $out));
        }
    }

    private function nodeBinary(): ?string
    {
        $candidates = array_filter([
            trim((string) @shell_exec('command -v node 2>/dev/null')),
            getenv('HOME') ? getenv('HOME').'/.nvm/versions/node/v18.20.4/bin/node' : null,
        ]);
        foreach ($candidates as $c) {
            if ($c !== '' && is_executable($c)) {
                return $c;
            }
        }

        return null;
    }
}
