<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\ViewModel;

use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Tests\TestCase;

/**
 * P3-02 grep gate `vm-bypass` (kit tools/conformance/grep_gates.py, THEME-CONTRACT §4): a kit theme reads only
 * `$vm`. Its Blade views (core/resources/views/store_templates/{table,brew,aisle,basket,book,_kit}/) may not call
 * store_options() / request() / theme_setting() / auth(), name a model class (\App\Models, \Juwri\…\Models), query
 * (DB::, ::where( / ::find( / ::query( / ::all() or read the legacy `$store` / `$postOptions` render variables.
 * `{{-- --}}` comments are ignored, as in the kit tool. The kit's four patterns are a subset of these.
 */
class KitThemeViewModelGateTest extends TestCase
{
    /** rule label => pattern */
    private const RULES = [
        'store_options(' => '/\bstore_options\s*\(/',
        'request(' => '/(?<![\w$>:\\\\])request\s*\(/',
        'theme_setting(' => '/\btheme_setting\s*\(/',
        'auth(' => '/(?<![\w$>:\\\\])auth\s*\(/',
        '\App\Models' => '/\\\\?App\\\\Models\b/',
        '\Juwri\…\Models' => '/\\\\?Juwri\\\\(?:Core|Modules\\\\\w+)\\\\Models\b/',
        'DB::' => '/\bDB::/',
        'Model query' => '/\b[A-Z]\w*::(?:where|find|findOrFail|query|all|first|with)\s*\(/',
        '$store' => '/\$store(?:Options)?\b/',
    ];

    /** @return list<string> "file:line: rule" for every bypass in a Blade source */
    public static function violations(string $source, string $file = 'view'): array
    {
        $code = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]), $source);
        $out = [];
        foreach (self::RULES as $label => $pattern) {
            if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [, $offset]) {
                    $out[] = $file.':'.(substr_count($code, "\n", 0, $offset) + 1).': vm-bypass `'.$label.'`';
                }
            }
        }

        return $out;
    }

    public function test_kit_theme_views_read_only_the_view_model(): void
    {
        $violations = [];
        $scanned = 0;
        foreach ([...KitThemes::SLUGS, '_kit'] as $theme) {
            $dir = resource_path('views/store_templates/'.$theme);
            if (! is_dir($dir)) {
                continue; // the kit theme's views arrive with P3-05 and later
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $scanned++;
                array_push($violations, ...self::violations((string) file_get_contents($file->getPathname()), substr($file->getPathname(), strlen(base_path()) + 1)));
            }
        }

        $this->assertSame([], $violations, "kit theme views may read only \$vm (THEME-CONTRACT §4):\n".implode("\n", $violations));
        $this->assertGreaterThanOrEqual(0, $scanned);
    }

    public function test_the_gate_catches_every_bypass_and_passes_a_view_model_template(): void
    {
        $bad = <<<'BLADE'
        <h1>{{ $store->title }}</h1>
        @php($o = store_options($vm['store']['public_id']))
        {{ request('q') }} {{ theme_setting(1, 'colors.primary') }} {{ auth()->id() }}
        @php($m = \App\Models\Order::first())
        @php($i = \Juwri\Modules\Menu\Models\Menu::where('id', 1)->first())
        @php($n = DB::table('items')->count())
        @foreach (Menu::all() as $x) @endforeach
        {{ $postOptions->currency_sign }}
        BLADE;
        $labels = array_map(fn (string $v) => substr($v, strpos($v, '`') + 1, -1), self::violations($bad));
        foreach (array_keys(self::RULES) as $rule) {
            $this->assertContains($rule, $labels, "the gate catches `{$rule}`");
        }

        $good = <<<'BLADE'
        {{-- store_options() is banned here; this comment is ignored --}}
        <h1>{{ $vm['store']['name'] }}</h1>
        @foreach ($vm['catalog']['categories'] as $category)
          <a href="#{{ $category['id'] }}">{{ $category['name'] }}</a>
        @endforeach
        <x-ui.icon name="cart" /> {{ ___('Total to pay') }} {{ $vm['page']['cart']['total']['formatted'] ?? '' }}
        <form action="{{ $vm['endpoints']['place'] }}" method="post">@csrf</form>
        BLADE;
        $this->assertSame([], self::violations($good));
    }
}
