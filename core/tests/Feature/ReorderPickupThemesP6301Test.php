<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-301 — "Order again" pickup across the six food themes.
 *
 * Each theme's account page persists the reorder payload under the store-scoped
 * localStorage key `cv_reorder_<slug>` and then redirects to the menu. Unless the
 * menu page reads that exact prefix back, the cart stays empty and the button
 * looks broken. The pickup is delegated to one shared helper
 * (`_shared/js/reorder-pickup.js`, `window.JuwriReorder.pickup`) that every menu
 * view loads.
 *
 * Browser-free: the contract is asserted on the view / asset source, not a DOM.
 * The test pins three things per theme:
 *   1. account.blade.php writes the `cv_reorder_` prefix,
 *   2. index.blade.php loads the shared `reorder-pickup` helper,
 *   3. the prefix is read back by the menu page — inline in index.blade.php or in
 *      the shared helper it loads.
 */
class ReorderPickupThemesP6301Test extends TestCase
{
    private const KEY_PREFIX = 'cv_reorder_';

    /**
     * Candidate homes for the shared helper. The task names the views tree; the
     * other shared post-template JS assets live under the repo-root assets tree,
     * so both are accepted — the point is that the loaded helper reads the key.
     *
     * @return list<string>
     */
    private function sharedScriptPaths(): array
    {
        return [
            resource_path('views/store_templates/_shared/js/reorder-pickup.js'),
            base_path('../assets/store_templates/_shared/js/reorder-pickup.js'),
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function themeProvider(): array
    {
        // every theme whose account page has a Reorder button (writes cv_reorder_<slug>)
        $themes = ['crave', 'crumb', 'dough', 'ember', 'glow', 'lime', 'matcha', 'orbit', 'picnic', 'plate', 'scoop', 'soiree', 'velora'];

        return array_combine($themes, array_map(fn (string $t): array => [$t], $themes));
    }

    private function themeScript(string $theme): string
    {
        $path = base_path("../assets/store_templates/{$theme}/js/script.js");
        $this->assertFileExists($path, "{$theme}: missing script.js");

        return (string) file_get_contents($path);
    }

    public function test_layout_loads_the_shared_helper_before_the_theme_bundle(): void
    {
        $layout = (string) file_get_contents(resource_path('views/store_templates/layout.blade.php'));
        $helper = strpos($layout, '_shared/js/reorder-pickup.js');
        $bundle = strpos($layout, '/js/script.js?ver=');

        $this->assertNotFalse($helper, 'store_templates.layout must load reorder-pickup.js');
        $this->assertNotFalse($bundle);
        $this->assertLessThan($bundle, $helper, 'the helper must load before the theme script.js (themes call it at boot)');
    }

    #[DataProvider('themeProvider')]
    public function test_menu_page_reads_the_reorder_key_the_account_page_writes(string $theme): void
    {
        $account = (string) file_get_contents(resource_path("views/store_templates/{$theme}/account.blade.php"));
        $index = (string) file_get_contents(resource_path("views/store_templates/{$theme}/index.blade.php"));
        $script = $this->themeScript($theme);

        $this->assertStringContainsString(self::KEY_PREFIX, $account, "{$theme}/account.blade.php must write ".self::KEY_PREFIX);

        // the theme calls the shared reader (from its script.js boot, or from its index blade)
        $this->assertTrue(
            str_contains($script, 'JuwriReorder.pickup') || str_contains($index, 'JuwriReorder.pickup'),
            "{$theme}: nothing calls JuwriReorder.pickup, so the cv_reorder_ payload is never consumed"
        );

        // the shared helper owns the key read
        $helper = '';
        foreach ($this->sharedScriptPaths() as $path) {
            if (is_file($path)) {
                $helper .= (string) file_get_contents($path);
            }
        }
        $this->assertStringContainsString(self::KEY_PREFIX, $helper);
    }

    #[DataProvider('themeProvider')]
    public function test_theme_pickup_carries_modifiers_and_the_note(string $theme): void
    {
        $script = $this->themeScript($theme);

        $this->assertMatchesRegularExpression('/window\.__'.$theme.'Pickup\s*=\s*function/', $script, "{$theme}: no __{$theme}Pickup line adder");

        // the adder maps the payload's choice-group options and note onto the cart line
        $this->assertStringContainsString('line.options', $script, "{$theme}: pickup ignores the reorder options");
        $this->assertStringContainsString('line.note', $script, "{$theme}: pickup drops the per-line note");

        // the old inline copy pushed every reordered line with mods: [] and note: ""
        $this->assertDoesNotMatchRegularExpression('/\|reorder"\s*\+\s*Math\.random/', $script, "{$theme}: the old inline pickupReorder (mods: []) is still there");
    }

    public function test_helper_carries_options_and_note_end_to_end(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not available');
        }

        $helper = base_path('../assets/store_templates/_shared/js/reorder-pickup.js');
        $script = <<<'JS'
const fs = require('fs');
const store = {};
const w = { localStorage: { getItem: k => store[k] ?? null, removeItem: k => { delete store[k]; } } };
new Function('window', 'document', fs.readFileSync(process.argv[2], 'utf8'))(w, {});
store.cv_reorder_demo = JSON.stringify([
  { id: 7, qty: 2, variantId: 3, order_note: 'no onions', extras: [{ id: 5, title: 'X', price: 1 }],
    options: [{ group_id: 9, title: 'Size', selected: [{ option: 'Large', price: 2 }] }] },
  { id: 8, qty: 1 },
]);
const item = { optionGroups: [{ id: 9, required: true, min: 1, options: [{ i: 0, label: 'Small', price: 0 }, { i: 1, label: 'Large', price: 2 }] }] };
const seen = [];
const r = w.JuwriReorder.pickup('demo', l => { seen.push(l); return l.item_id === 7; }, { toast: m => seen.push(m) });
const mods = w.JuwriReorder.modsFromOptions(item, seen[0].options);
console.log(JSON.stringify({
  added: r.added, unavailable: r.unavailable.length, note: seen[0].note, qty: seen[0].qty, variant: seen[0].variant,
  mods, requiredOk: w.JuwriReorder.requiredMissing(item, mods), requiredMissing: w.JuwriReorder.requiredMissing(item, []),
  keyConsumed: store.cv_reorder_demo === undefined, toasted: seen.filter(x => typeof x === 'string').length,
}));
JS;
        $file = tempnam(sys_get_temp_dir(), 'reorder').'.js';
        file_put_contents($file, $script);
        $out = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' '.escapeshellarg($helper).' 2>&1');
        @unlink($file);

        $r = json_decode(trim($out), true);
        $this->assertIsArray($r, 'node output: '.$out);
        $this->assertSame(1, $r['added']);
        $this->assertSame(1, $r['unavailable']);
        $this->assertSame('no onions', $r['note']);
        $this->assertSame(2, $r['qty']);
        $this->assertSame(3, $r['variant']);
        $this->assertSame([['groupId' => 9, 'optIndex' => 1, 'label' => 'Large', 'price' => 2]], $r['mods']);
        $this->assertFalse($r['requiredOk']);
        $this->assertTrue($r['requiredMissing']);
        $this->assertTrue($r['keyConsumed']);
        $this->assertSame(1, $r['toasted']);
    }
}
