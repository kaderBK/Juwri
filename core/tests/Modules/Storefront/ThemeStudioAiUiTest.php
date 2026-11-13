<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio — AI theme builder UI (lane D-ui, spec rev/theme-studio §4 Phase D,
 * contract rev/theme-studio/AI-BUILDER-CONTRACT.md).
 *
 * The owner page is rendered through a test-only route with a fixture $studio
 * carrying caps.ai_generate + urls.ai_*; the superadmin view with a fixture
 * $config. The real routes/controllers belong to lane D-core.
 */
class ThemeStudioAiUiTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'owner.design.studio';

    private const ADMIN_VIEW = 'admin.theme_studio.generate';

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

        Route::middleware('web')->get('/_test/theme-studio-ai', function () {
            app()->setLocale((string) request('lang', 'en'));

            return view(self::VIEW, ['studio' => $this->fixture(
                request()->boolean('picker'),
                ! request()->boolean('nocap'),
                ! request()->boolean('nourls'),
            )]);
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
    private function fixture(bool $picker, bool $cap, bool $aiUrls): array
    {
        $base = 'https://example.test/dashboard/theme-studio';
        $urls = [
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
        ];
        if ($aiUrls) {
            $urls += [
                'ai_generate' => $base.'/ai/generate?store=999001',
                'ai_set' => $base.'/ai/sets/__SET__?store=999001',
                'ai_preview' => $base.'/ai/preview/__SET__/__P__?store=999001',
                'ai_apply' => $base.'/ai/apply/__SET__/__P__?store=999001',
            ];
        }

        return [
            'store' => [
                'id' => 999001, 'slug' => 'burger-house', 'title' => 'Burger House', 'store_type' => 'restaurant',
                'languages' => [['code' => 'ar', 'name' => 'العربية', 'dir' => 'rtl'], ['code' => 'en', 'name' => 'English', 'dir' => 'ltr']],
                'default_language' => 'ar',
            ],
            'stores' => [['id' => 999001, 'title' => 'Burger House']],
            'theme' => $picker ? null : ['template' => 'mp:studio-bistro@1', 'slug' => 'studio-bistro', 'version' => '1', 'name' => 'Bistro', 'is_studio' => true],
            'themes' => [['slug' => 'studio-bistro', 'name' => 'Bistro', 'vertical_groups' => ['food'], 'thumb' => null]],
            'recipes' => [['id' => 'restaurant/classic', 'name' => 'Classic restaurant', 'vertical' => 'restaurant']],
            'caps' => ['sections_add' => true, 'sections_remove' => true, 'sections_reorder' => true, 'blocks_edit' => true, 'tokens' => true, 'fonts' => true, 'custom_block' => false, 'ai_generate' => $cap],
            'limits' => ['max_sections' => 25, 'max_blocks' => 50],
            'urls' => $urls,
            'ai' => ['image_cost' => 4, 'remaining' => 7],
            'csrf' => 'csrf-token-fixture',
            'i18n' => [],
        ];
    }

    private function page(string $query = ''): string
    {
        return (string) $this->actingAs($this->owner())
            ->get('/_test/theme-studio-ai'.($query !== '' ? '?'.$query : ''))
            ->assertOk()
            ->getContent();
    }

    /** The superadmin generator's inline page script (WI-10 kit rebuild). */
    private function adminGeneratorScript(): string
    {
        $blade = File::get(resource_path('views/admin/theme_studio/generate.blade.php'));
        $this->assertSame(1, preg_match('#<script nonce="\{\{ csp_nonce\(\) \}\}">(.*?)</script>#s', $blade, $m), 'inline generator script missing');

        return $m[1];
    }

    /** @return array<string,mixed> */
    private function block(string $html, string $id): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="'.preg_quote($id, '#').'">(.*?)</script>#s', $html, $m), "{$id} block missing");
        $data = json_decode($m[1], true);
        $this->assertIsArray($data, "{$id} is not valid JSON");

        return $data;
    }

    public function test_editor_top_bar_gets_the_ai_action_and_the_panel(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-mode="editor"', $html);
        // Top-bar action + the dialog it opens.
        $this->assertMatchesRegularExpression('#<button[^>]*class="[^"]*ts-ai-open[^"]*"[^>]*data-ts-ai-open#s', $html);
        $this->assertStringContainsString('<dialog id="ts-ai"', $html);
        $this->assertStringNotContainsString('class="ts-ai-entry"', $html, 'the picker entry card is picker-only');

        foreach ([
            'data-ts-ai-form', 'data-ts-ai-text', 'maxlength="500"', 'data-ts-ai-count', 'data-ts-ai-add-color', 'data-ts-ai-images',
            'data-ts-ai-view="progress"', 'data-ts-ai-view="results"', 'data-ts-ai-cancel', 'data-ts-ai-cards', 'data-ts-ai-regenerate',
            'data-ts-ai-fallback', 'role="progressbar"', 'aria-live="polite"', 'role="alert"',
            'Generate an AI hero image (uses Studio credits)', 'Costs 4 Studio credits per generation.',
            'You can create 7 more designs today.', 'Using a design replaces your current draft.',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "AI panel is missing {$needle}");
        }
        foreach (['warm', 'minimal', 'bold', 'luxury', 'playful', 'traditional', 'modern', 'family'] as $vibe) {
            $this->assertStringContainsString('data-ts-ai-vibe="'.$vibe.'"', $html);
        }
        foreach (['fast_ordering', 'brand_story', 'browse', 'bookings'] as $priority) {
            $this->assertMatchesRegularExpression('#<input type="radio" name="priority" value="'.$priority.'"#', $html);
        }

        $cfg = $this->block($html, 'theme-studio-config');
        $this->assertTrue($cfg['caps']['ai_generate']);
        $this->assertSame('https://example.test/dashboard/theme-studio/ai/preview/__SET__/__P__?store=999001', $cfg['urls']['ai_preview']);

        $ai = $this->block($html, 'theme-studio-ai-i18n');
        $this->assertTrue($ai['editor']);
        $this->assertSame(7, $ai['remaining']);
        $this->assertSame('Use this', $ai['strings']['Use this']);
        $this->assertArrayHasKey('Design :n of :total', $ai['strings']);
    }

    public function test_picker_gets_the_build_with_ai_entry_card(): void
    {
        $html = $this->page('picker=1');

        $this->assertStringContainsString('data-mode="picker"', $html);
        $this->assertStringContainsString('class="ts-ai-entry"', $html);
        $this->assertStringContainsString('Build my theme with AI', $html);
        $this->assertMatchesRegularExpression('#class="ts-ai-entry".*?data-ts-ai-open#s', $html);
        $this->assertStringContainsString('<dialog id="ts-ai"', $html);
        // The regular picker is untouched.
        $this->assertStringContainsString('data-ts-start-theme="studio-bistro"', $html);
        $this->assertStringNotContainsString('Using a design replaces your current draft.', $html, 'no draft to replace on the picker');
        $this->assertFalse($this->block($html, 'theme-studio-ai-i18n')['editor']);
    }

    public function test_no_ai_ui_without_the_cap_or_without_the_endpoints(): void
    {
        foreach (['nocap=1', 'nocap=1&picker=1', 'nourls=1', 'nourls=1&picker=1'] as $query) {
            $html = $this->page($query);
            foreach (['data-ts-ai-open', 'id="ts-ai"', 'ts-ai-entry', 'studio-ai.js', 'studio-ai.css', 'theme-studio-ai-i18n'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$query}: {$needle} must not render");
            }
            // The editor itself still renders.
            $this->assertStringContainsString('assets/theme-studio/studio.js', $html);
        }
    }

    public function test_ai_assets_are_included_and_nonced(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*assets/theme-studio/studio-ai\.css\?v=\d+">#', $html);
        $this->assertMatchesRegularExpression('#<script src="[^"]*assets/theme-studio/studio-ai\.js\?v=\d+" nonce="[^"]+" defer></script>#', $html);

        foreach ([
            resource_path('views/owner/design/studio.blade.php'),
            resource_path('views/owner/design/studio-ai-panel.blade.php'),
            resource_path('views/admin/theme_studio/generate.blade.php'),
        ] as $file) {
            $view = File::get($file);
            preg_match_all('#<script\b[^>]*>#', $view, $tags);
            foreach ($tags[0] as $tag) {
                $this->assertTrue(
                    str_contains($tag, 'nonce="{{ csp_nonce() }}"') || str_contains($tag, 'type="application/json"'),
                    basename($file).": script tag without a nonce: {$tag}"
                );
            }
            preg_match_all('#<style\b[^>]*>#', $view, $styles);
            foreach ($styles[0] as $tag) {
                $this->assertStringContainsString('nonce="{{ csp_nonce() }}"', $tag, basename($file).': style tag without a nonce');
            }
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|input|submit|load)\s*=/i', $view, basename($file).': no inline event handlers (CSP)');
        }
    }

    public function test_ai_panel_mirrors_for_an_arabic_dashboard(): void
    {
        $rtl = $this->page('lang=ar');
        $this->assertMatchesRegularExpression('#<div class="ts-app" id="theme-studio" dir="rtl"#', $rtl);
        // The dialog lives inside the mirrored app root.
        $this->assertMatchesRegularExpression('#id="theme-studio" dir="rtl".*<dialog id="ts-ai"#s', $rtl);

        $css = preg_replace('#/\*.*?\*/#s', '', File::get(base_path('../assets/theme-studio/studio-ai.css')));
        $this->assertDoesNotMatchRegularExpression('/(margin|padding)-(left|right)\s*:|float\s*:\s*(left|right)|text-align\s*:\s*(left|right)|(?<![a-z-])(left|right)\s*:/', $css, 'studio-ai.css must use logical properties');
        $this->assertStringContainsString('[dir="rtl"] .ts-ai-card__frame', $css, 'the scaled preview must anchor to the start edge in RTL');
    }

    public function test_js_uses_no_native_dialogs_and_honours_the_contract(): void
    {
        // WI-10: the superadmin generator script now lives inline in its kit view.
        foreach (['theme-studio/studio-ai.js' => null, 'admin/theme_studio/generate.blade.php' => true] as $file => $inline) {
            $js = $inline ? $this->adminGeneratorScript() : File::get(base_path('../assets/'.$file));
            $js = preg_replace('#/\*.*?\*/#s', '', $js);
            $js = preg_replace('#(^|[^:])//[^\n]*#', '$1', $js);
            $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z.])(alert|confirm|prompt)\s*\(/', $js, "{$file} calls a native dialog");
            $this->assertDoesNotMatchRegularExpression('/\beval\s*\(|new Function\s*\(|\.innerHTML\s*=/', $js, "{$file} evaluates code or writes raw HTML");
            $this->assertStringContainsString("'X-CSRF-TOKEN'", $js, "{$file} must send the CSRF token");
            $this->assertStringContainsString("'__SET__'", $js);
            $this->assertStringContainsString("'__P__'", $js);
        }

        $owner = File::get(base_path('../assets/theme-studio/studio-ai.js'));
        foreach (['urls.ai_generate', 'urls.ai_preview', 'urls.ai_apply', 'data.fallback', 'res.status === 429', 'res.status === 422', 'AbortController', 'turbo:load', 'turbo:before-cache', 'data.redirect'] as $needle) {
            $this->assertStringContainsString($needle, $owner, "studio-ai.js is missing {$needle}");
        }
        // Never edits studio.js; only talks to it through its public API.
        $this->assertStringContainsString('window.ThemeStudio.current()', $owner);

        $admin = $this->adminGeneratorScript();
        foreach (['urls.generate', 'urls.save', 'review_url', 'vertical: S.vertical'] as $needle) {
            $this->assertStringContainsString($needle, $admin, "the admin generator script is missing {$needle}");
        }
    }

    public function test_dashboard_native_dialog_ratchet_covers_the_new_partial(): void
    {
        $body = File::get(resource_path('views/owner/design/studio-ai-panel.blade.php'));
        $body = preg_replace('/\{\{--.*?--\}\}/s', '', $body);
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z])(confirm|prompt|alert)\s*\(/', $body);
    }

    public function test_admin_generator_view_renders_with_a_fixture_config(): void
    {
        // The real admin shell needs the full settings row + admin routes; a stub
        // layout (same yields/stacks) isolates this page's own markup.
        $stub = storage_path('framework/testing/tsg-layout-'.getmypid());
        File::ensureDirectoryExists($stub.'/admin');
        File::put($stub.'/admin/layout.blade.php', "@stack('styles')<main>@yield('content')</main>@stack('scripts_at_bottom')");
        View::getFinder()->flush();
        View::getFinder()->prependLocation($stub);

        $html = view(self::ADMIN_VIEW, ['config' => [
            'verticals' => [['type' => 'restaurant', 'label' => 'Restaurant'], ['type' => 'pharmacy', 'label' => 'Pharmacy <b>x</b>']],
            'urls' => [
                'generate' => 'https://example.test/admin/theme-studio/generate',
                'preview' => 'https://example.test/admin/theme-studio/preview/__SET__/__P__',
                'save' => 'https://example.test/admin/theme-studio/save',
            ],
        ]])->render();
        File::deleteDirectory($stub);

        $this->assertStringContainsString('<option value="restaurant">Restaurant</option>', $html);
        // WI-10 (kit rebuild): behaviour is an inline nonce'd page script, no retired admin asset.
        $this->assertMatchesRegularExpression('#<script nonce="[^"]+">\s*\(function \(\) \{#', $html);
        $this->assertStringNotContainsString('assets/admin/', $html);
        $this->assertMatchesRegularExpression('#<style nonce="[^"]+">#', $html);
        $this->assertStringContainsString('Pharmacy &lt;b&gt;x&lt;/b&gt;', $html, 'labels are escaped');
        foreach (['id="tsg-form"', 'id="tsg-text"', 'maxlength="500"', 'data-tsg-vibe="luxury"', 'value="bookings"', 'data-tsg-add-color', 'data-tsg-cards', 'role="progressbar"', 'aria-live="polite"'] as $needle) {
            $this->assertStringContainsString($needle, $html, "admin generator is missing {$needle}");
        }
        $cfg = $this->block($html, 'tsg-config');
        $this->assertSame('https://example.test/admin/theme-studio/preview/__SET__/__P__', $cfg['urls']['preview']);
        $this->assertSame('https://example.test/admin/theme-studio/save', $cfg['urls']['save']);
        $this->assertArrayHasKey('Save as theme', $cfg['i18n']);
    }

    public function test_js_assets_parse(): void
    {
        $node = $this->nodeBinary();
        if ($node === null) {
            $this->markTestSkipped('node is not installed');
        }
        $inline = storage_path('framework/testing/tsg-inline-'.getmypid().'.js');
        File::ensureDirectoryExists(dirname($inline));
        File::put($inline, $this->adminGeneratorScript());
        foreach (['theme-studio/studio-ai.js' => realpath(base_path('../assets/theme-studio/studio-ai.js')), 'admin generator (inline)' => $inline] as $file => $path) {
            $this->assertNotFalse($path, "{$file} is missing");
            exec(escapeshellarg($node).' --check '.escapeshellarg((string) $path).' 2>&1', $out, $code);
            $this->assertSame(0, $code, $file.': '.implode("\n", $out));
        }
        File::delete($inline);
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
