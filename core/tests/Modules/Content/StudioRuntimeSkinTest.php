<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Symfony\Component\Process\Process;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio R2: the library's RUNTIME SKIN (tools/liquid-library/src/runtime)
 * styles every piece of markup commerce runtime v2 renders itself — product
 * view, related rail, qty, stock, slot picker, dialogs, account, order
 * confirmed, tips … — and the header hosts a product view on every store.
 */
class StudioRuntimeSkinTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- build

    public function test_build_accepts_runtime_skin_files(): void
    {
        $out = $this->build(['ok.css' => ".mb-x{color:var(--tc-text,#111)}\ndialog.mb-dialog:not([open]){display:none}\n.mb-x .y,.mb-x::backdrop{margin-inline-start:4px}\n@keyframes mb-ok-spin{to{opacity:.5}}"]);
        $this->assertSame(0, $out['exit'], $out['output']);
        $this->assertStringContainsString('dry run', $out['output']);
    }

    public function test_build_rejects_a_runtime_selector_outside_the_mb_namespace(): void
    {
        foreach (['body{margin:0}', '.foo .mb-x{color:var(--tc-text,#111)}', '.mbs-header{color:var(--tc-text,#111)}', '.mb-x,h1{margin:0}', 'div.mb-x{margin:0}'] as $css) {
            $out = $this->build(['bad.css' => $css]);
            $this->assertSame(1, $out['exit'], "accepted: {$css}");
            $this->assertStringContainsString('must start with .mb- or dialog.mb-', $out['output'], $css);
        }
    }

    public function test_build_keeps_the_other_contract_rules_for_runtime_files(): void
    {
        $cases = [
            '.mb-x{margin-left:4px}' => 'physical property',
            '.mb-x{color:#fff}' => 'raw colour',
            '.mb-x{background:url(a.png)}' => 'url()',
            '.mb-x{transform:translateX(4px)}' => 'horizontal translate',
            '@keyframes spin{to{opacity:0}}' => 'must be named mb-bad-',
            '@import "x.css";' => '@import is not allowed',
        ];
        foreach ($cases as $css => $message) {
            $out = $this->build(['bad.css' => $css]);
            $this->assertSame(1, $out['exit'], "accepted: {$css}");
            $this->assertStringContainsString($message, $out['output'], $css);
        }
    }

    public function test_committed_library_css_is_current_and_in_budget(): void
    {
        $node = $this->node();
        if ($node === null) {
            $this->markTestSkipped('node is not available');
        }
        $process = new Process([$node, base_path('../tools/liquid-library/build.mjs'), '--check']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        $css = $this->libraryCss();
        $this->assertLessThanOrEqual(96 * 1024, strlen($css));
        $this->assertLessThanOrEqual(15 * 1024, strlen((string) gzencode($css, 9)));
    }

    // ------------------------------------------------------------- coverage

    public function test_every_class_the_v2_runtime_renders_has_a_rule_in_library_css(): void
    {
        $classes = $this->runtimeClasses();
        // Sanity: the parse sees the surfaces this skin exists for.
        foreach (['mb-prod', 'mb-rail-card', 'mb-qty', 'mb-stock', 'mb-slot', 'mb-slots', 'mb-dialog', 'mb-dialog-card', 'mb-account', 'mb-addr', 'mb-confirm', 'mb-confetti', 'mb-tip', 'mb-item-meta', 'mb-toast', 'mb-sheet', 'mb-cart'] as $expected) {
            $this->assertContains($expected, $classes, "parser missed {$expected}");
        }
        $this->assertGreaterThan(80, count($classes));

        $styled = $this->styledRuntimeClasses($this->libraryCss());
        $missing = array_values(array_diff($classes, $styled));
        $this->assertSame([], $missing, 'runtime classes without a library.css rule: '.implode(', ', $missing));
    }

    public function test_runtime_rules_are_logical_tokenised_and_accessible(): void
    {
        $css = $this->libraryCss();
        foreach ($this->selectors($css) as $selector) {
            $this->assertMatchesRegularExpression('/^(\.mbs-[a-z0-9-]|(dialog)?\.mb-[a-z0-9])/', $selector, "unscoped selector: {$selector}");
        }
        $this->assertStringContainsString('dialog.mb-dialog:not([open]){display:none}', $css, 'a closed native dialog stays hidden');
        $this->assertStringContainsString('.mb-dialog::backdrop', $css);
        $this->assertMatchesRegularExpression('/@media \\(prefers-reduced-motion: ?reduce\\)\\{[^}]*\\.mb-confetti\\{display:none\\}/', $css, 'motion off for confetti');
        $this->assertMatchesRegularExpression('/\.mb-prod :focus-visible[^{]*\{outline:2px solid/', $css, 'focus ring in the product view');
        $this->assertStringContainsString('.mb-qty button,.mb-cl-qty button{inline-size:44px;block-size:44px', $css, '44px qty targets');
        // Rules moved out of header.css — the header no longer re-skins runtime markup.
        $this->assertStringNotContainsString('.mbs-header__host .mb-cart-line', $css);
        $this->assertStringNotContainsString('.mbs-header__host .mb-opt{', $css);
    }

    public function test_favourites_view_uses_runtime_classes_with_rules_in_library_css(): void
    {
        $v2 = (string) file_get_contents(public_path('assets/commerce-runtime/v2/commerce.js'));
        $favs = $this->between($v2, 'function favCardHTML', 'fitment');
        $this->assertDoesNotMatchRegularExpression('/class="cl-/', $favs, 'v2 favourites render mb-* markup, not crave cl-*');
        $this->assertDoesNotMatchRegularExpression('/class="cl-/', $v2);

        foreach (['mb-favs-grid', 'mb-fav-card', 'mb-fav-media', 'mb-fav-btn', 'mb-fav-body', 'mb-fav-name', 'mb-fav-price', 'mb-fav-add', 'mb-favs-empty'] as $class) {
            $this->assertContains($class, $this->runtimeClasses(), "v2 renders {$class}");
            $this->assertContains($class, $this->styledRuntimeClasses($this->libraryCss()), "{$class} has a library.css rule");
        }
        $this->assertMatchesRegularExpression('/\.mb-fav-btn,\.mb-fav-add\{[^}]*inline-size:44px;block-size:44px/', $this->libraryCss(), '44px heart / add targets');

        // v1 stays untouched: crave's own markup.
        $v1 = (string) file_get_contents(public_path('assets/commerce-runtime/v1/commerce.js'));
        $this->assertStringContainsString('class="cl-card"', $v1);
    }

    public function test_runtime_dialogs_trap_tab_focus_and_restore_it(): void
    {
        $v2 = (string) file_get_contents(public_path('assets/commerce-runtime/v2/commerce.js'));

        $trap = $this->between($v2, 'function trapFocus(', "\n  }");
        $this->assertStringContainsString('ev.key !== "Tab"', $trap);
        $this->assertStringContainsString('last.focus()', $trap, 'Shift+Tab on the first control wraps to the last');
        $this->assertStringContainsString('first.focus()', $trap, 'Tab on the last control wraps to the first');

        // The mb-dialog traps Tab in BOTH the native <dialog> and the ARIA fallback …
        $dialog = $this->between($v2, 'function mbDialog(', 'return d;');
        $this->assertStringContainsString('trapFocus(d, ev)', $dialog);
        $this->assertStringNotContainsString('|| native) return;', $dialog, 'no native-only early return before the trap');
        $this->assertStringContainsString('opener.focus()', $dialog, 'focus goes back to the opener on close');
        // … and so does the option sheet (role=dialog aria-modal).
        $this->assertStringContainsString('trapFocus(mount, ev)', $v2);
        $this->assertStringContainsString('function closeItemSheet(', $v2);
    }

    // --------------------------------------------------------------- header

    public function test_studio_dev_page_hosts_the_product_view_once(): void
    {
        $html = $this->get(route('publicView', $this->store(true)->slug))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-mb-view="product"'));
        $this->assertSame(1, substr_count($html, 'data-mb-product-mount'));
        $this->assertSame(1, substr_count($html, 'data-mb-cart-mount'));
        $this->assertMatchesRegularExpression('/data-mb-view="product"[^>]*aria-label="[^"]+"/', $html);
        $product = $this->between($html, 'data-mb-view="product"', '</section>');
        $this->assertStringContainsString('data-mb-back', $product, 'product view has a back button');
        $this->assertSame(1, substr_count($html, 'window.MB_COMMERCE='));
    }

    public function test_view_only_store_still_gets_the_product_view(): void
    {
        $html = $this->get(route('publicView', $this->store(false)->slug))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-mb-product-mount'), 'card taps must open details without ordering');
        $this->assertStringNotContainsString('data-mb-cart-mount', $html);
        $this->assertStringContainsString('data-mb-open=', $html, 'cards opt into the product view');
    }

    public function test_product_view_renders_in_arabic(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'العربية', 'direction' => 'rtl', 'active' => 1]);
        $store = $this->store(true);
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['ar', 'en']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $product = $this->between($html, 'data-mb-view="product"', '</section>');
        $this->assertStringContainsString('aria-label="رجوع"', $product, 'Arabic back label');
    }

    // -------------------------------------------------------------- helpers

    /**
     * Run build.mjs --dry against a throwaway source tree holding only runtime files.
     *
     * @param  array<string,string>  $runtimeFiles
     * @return array{exit:int,output:string}
     */
    private function build(array $runtimeFiles): array
    {
        $node = $this->node();
        if ($node === null) {
            $this->markTestSkipped('node is not available');
        }
        $dir = storage_path('framework/testing/runtime-skin-'.Str::lower(Str::random(10)));
        $this->tmp[] = $dir;
        File::ensureDirectoryExists($dir.'/runtime');
        foreach ($runtimeFiles as $name => $css) {
            File::put($dir.'/runtime/'.$name, $css);
        }
        $process = new Process([$node, base_path('../tools/liquid-library/build.mjs'), '--src='.$dir, '--dry']);
        $process->run();

        return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
    }

    private function libraryCss(): string
    {
        $path = public_path('assets/liquid-library/1/library.css');
        $this->assertFileExists($path, 'run node tools/liquid-library/build.mjs');

        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
    }

    /**
     * Every `mb-*` class commerce runtime v2 puts in markup: class="…" attributes
     * inside its HTML strings (up to the first quote, so concatenated state
     * classes are cut off) and className = "…" assignments.
     *
     * @return list<string>
     */
    private function runtimeClasses(): array
    {
        $js = (string) file_get_contents(public_path('assets/commerce-runtime/v2/commerce.js'));
        $found = [];
        preg_match_all('/class="([^"\']*)/', $js, $m1);
        preg_match_all('/className\s*=\s*"([^"]*)"/', $js, $m2);
        foreach (array_merge($m1[1], $m2[1]) as $attr) {
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $token) {
                if (preg_match('/^mb-[a-z0-9-]*[a-z0-9]$/', $token)) {
                    $found[$token] = true;
                }
            }
        }
        $classes = array_keys($found);
        sort($classes);

        return $classes;
    }

    /** @return list<string> every `mb-*` class named by some selector */
    private function styledRuntimeClasses(string $css): array
    {
        $found = [];
        foreach ($this->selectors($css) as $selector) {
            preg_match_all('/\.(mb-[a-z0-9-]+)(?![a-z0-9_-])/', $selector, $m);
            foreach ($m[1] as $class) {
                $found[$class] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Every selector (comma part) of a minified stylesheet, @media/@supports
     * bodies included, @keyframes frames skipped.
     *
     * @return list<string>
     */
    private function selectors(string $css): array
    {
        $out = [];
        $depth = 0;
        $buf = '';
        $skipUntilDepth = null;
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];
            if ($c === '{') {
                $prelude = trim($buf);
                $buf = '';
                if ($skipUntilDepth === null) {
                    if (str_starts_with($prelude, '@keyframes')) {
                        $skipUntilDepth = $depth;
                    } elseif (! str_starts_with($prelude, '@')) {
                        $parts = [];
                        $level = 0;
                        $cur = '';
                        foreach (str_split($prelude) as $ch) {
                            $level += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
                            if ($ch === ',' && $level === 0) {
                                $parts[] = trim($cur);
                                $cur = '';

                                continue;
                            }
                            $cur .= $ch;
                        }
                        $parts[] = trim($cur);
                        array_push($out, ...$parts);
                        $end = strpos($css, '}', $i);
                        $i = $end === false ? $len : $end;

                        continue;
                    }
                }
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                $buf = '';
                if ($skipUntilDepth !== null && $depth === $skipUntilDepth) {
                    $skipUntilDepth = null;
                }
            } else {
                $buf .= $c;
            }
        }

        return array_values(array_filter($out, fn ($s) => $s !== ''));
    }

    private function between(string $html, string $start, string $end): string
    {
        $a = strpos($html, $start);
        $this->assertNotFalse($a, "missing {$start}");
        $b = strpos($html, $end, $a);

        return substr($html, $a, ($b === false ? strlen($html) : $b) - $a);
    }

    private function node(): ?string
    {
        foreach (array_filter([getenv('NODE_BINARY') ?: null, trim((string) @shell_exec('command -v node 2>/dev/null'))]) as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        foreach (glob((getenv('HOME') ?: '').'/.nvm/versions/node/*/bin/node') ?: [] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function store(bool $ordering): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => $ordering ? 1 : 0, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:studio-dev@1');
        if ($ordering) {
            StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        return $store;
    }
}
