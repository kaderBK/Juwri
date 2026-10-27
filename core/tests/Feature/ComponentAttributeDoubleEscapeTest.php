<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * Found by Lane A's laundry journey: `<x-ui.field label="{{ $x }}">` escapes $x while the tag is compiled and the
 * component escapes it again when it prints the prop, so "Fish & Chips" reached the browser as `Fish &amp;amp; Chips`
 * (and an edit form saved the mangled text back). The fix is the bound form `:label="$x"` — the component then
 * escapes exactly once. None of the kit components prints a prop raw, so a `{{ }}` attribute is always a double escape.
 */
class ComponentAttributeDoubleEscapeTest extends TestCase
{
    private const NASTY = 'A & B <i>x</i> "q" \'z\'';

    private const ONCE = 'A &amp; B &lt;i&gt;x&lt;/i&gt; &quot;q&quot; &#039;z&#039;';

    /**
     * Every kit-component PROP that the views used to fill with `prop="{{ $x }}"`. A prop is printed by the component
     * with `{{ }}`, so the pre-escaped text was escaped a second time.
     *
     * @return array<string, array{0: string, 1: bool}> [blade using :prop="$s", whether the escaped text is printed verbatim]
     */
    public static function components(): array
    {
        return [
            'ui.field label' => ['<x-ui.field name="n" :label="$s" />', true],
            'ui.field value' => ['<x-ui.field name="n" label="L" :value="$s" />', true],
            'ui.field textarea value' => ['<x-ui.field name="n" type="textarea" label="L" :value="$s" />', true],
            'ui.field help' => ['<x-ui.field name="n" label="L" :help="$s" />', true],
            'ui.field id' => ['<x-ui.field name="n" label="L" :id="$s" />', true],
            'ui.switch label' => ['<x-ui.switch name="a" :label="$s" />', true],
            'ui.switch id' => ['<x-ui.switch name="a" :id="$s" />', true],
            'ui.check label' => ['<x-ui.check name="a" :label="$s" />', true],
            'ui.sheet id' => ['<x-ui.sheet :id="$s" title="T">B</x-ui.sheet>', true],
            'ui.sheet title' => ['<x-ui.sheet id="i" :title="$s">B</x-ui.sheet>', true],
            'ui.sheet description' => ['<x-ui.sheet id="i" title="T" :description="$s">B</x-ui.sheet>', true],
            'ui.stat label' => ['<x-ui.stat value="1" :label="$s" />', true],
            'ui.stat value' => ['<x-ui.stat :value="$s" label="L" />', true],
            'ui.card title' => ['<x-ui.card :title="$s">B</x-ui.card>', true],
            'ui.row title' => ['<x-ui.row :title="$s" />', true],
            'ui.row sub' => ['<x-ui.row title="T" :sub="$s" />', true],
            'ui.icon-button label' => ['<x-ui.icon-button :label="$s" />', true],
            'busy-button busy-label' => ['<x-busy-button :busy-label="$s">Save</x-busy-button>', true],
            // these two print the value through @js() (JSON in an Alpine expression): no verbatim form, but no entities either
            'upload-progress button-label' => ['<x-upload-progress url="/u" :button-label="$s" />', false],
            'job-progress unit' => ['<x-job-progress url="/u" :unit="$s" />', false],
        ];
    }

    #[DataProvider('components')]
    public function test_a_bound_attribute_is_escaped_exactly_once(string $blade, bool $verbatim): void
    {
        $html = Blade::render($blade, ['s' => self::NASTY]);

        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
        $this->assertStringNotContainsString('&amp;#039;', $html);
        $this->assertStringNotContainsString('<i>x</i>', $html, 'raw markup reached the page');

        if ($verbatim) {
            $this->assertStringContainsString(self::ONCE, $html);
        }
    }

    public function test_a_url_with_a_query_string_keeps_a_single_ampersand_entity(): void
    {
        $html = Blade::render('<x-ui.button :href="$u">Go</x-ui.button>', ['u' => '/x?a=1&b=2']);

        $this->assertStringContainsString('href="/x?a=1&amp;b=2"', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /** The old form is the bug: documents it so nobody "simplifies" the fix back. */
    public function test_the_interpolated_form_is_what_double_escapes(): void
    {
        $html = Blade::render('<x-ui.field name="n" label="{{ $s }}" />', ['s' => 'A & B']);

        $this->assertStringContainsString('A &amp;amp; B', $html);

        $html = Blade::render('<x-ui.button href="{{ $u }}">Go</x-ui.button>', ['u' => '/x?a=1&b=2']);

        $this->assertStringContainsString('href="/x?a=1&amp;amp;b=2"', $html);
    }

    /**
     * Attributes that are NOT declared props travel in $attributes, which Blade already renders without re-encoding —
     * `title="{{ $x }}"` / `data-*="{{ $x }}"` on those are correct (once-escaped) and deliberately left alone.
     */
    #[DataProvider('bagAttributes')]
    public function test_attributes_in_the_bag_are_escaped_once_even_when_interpolated(string $blade): void
    {
        $html = Blade::render($blade, ['s' => self::NASTY]);

        $this->assertStringContainsString(self::ONCE, $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('<i>x</i>', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function bagAttributes(): array
    {
        return [
            'ui.button title' => ['<x-ui.button title="{{ $s }}">Go</x-ui.button>'],
            'ui.button data attribute' => ['<x-ui.button data-ui-open="{{ $s }}">Go</x-ui.button>'],
            'ui.tag title' => ['<x-ui.tag title="{{ $s }}">T</x-ui.tag>'],
            'ui.pill title' => ['<x-ui.pill title="{{ $s }}">T</x-ui.pill>'],
            'ui.switch aria-label' => ['<x-ui.switch name="a" aria-label="{{ $s }}" />'],
            'ui.stat title' => ['<x-ui.stat value="1" label="L" title="{{ $s }}" />'],
        ];
    }

    /**
     * Ratchet: no kit-component tag in any view passes a declared @props attribute as `prop="…{{ … }}…"`. Use `:prop="…"`.
     * Lane A's three catalog forms are exempt until that lane merges (it applies the same rewrite to them);
     * Alpine/Livewire-style attributes (`x-on:*`, `@*`) are compiled by Alpine, not passed to a component prop.
     */
    public function test_no_view_interpolates_into_a_component_attribute(): void
    {
        $exempt = [
            'owner/catalog/pharmacy/edit.blade.php',
            'owner/catalog/retail/partials/form.blade.php',
            'owner/catalog/service/partials/form.blade.php',
        ];

        $offenders = [];
        foreach (ViewRoots::all() as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $rel = ViewRoots::relative($file->getPathname());
                if (in_array($rel, $exempt, true)) {
                    continue;
                }
                // drop Blade comments (the components' own usage docs)
                $body = preg_replace('/\{\{--.*?--\}\}/s', '', File::get($file->getPathname()));

                if (! preg_match_all('/<x-[A-Za-z0-9_.:-]+((?:\s+(?:"[^"]*"|\'[^\']*\'|\{\{.*?\}\}|[^>"\'])*)?)\s*\/?>/s', $body, $tags)) {
                    continue;
                }
                foreach ($tags[1] as $i => $attrs) {
                    if (! preg_match_all('/(?<![:\w@.-])([\w:.-]+)="[^"]*\{\{[^"]*"/s', $attrs, $m)) {
                        continue;
                    }
                    $props = self::propsOf($tags[0][$i]);
                    foreach ($m[1] as $name) {
                        if (in_array($name, $props, true)) {
                            $offenders[] = $rel.' <'.self::componentName($tags[0][$i]).' '.$name.'>';
                        }
                    }
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders, "Use :attr=\"expr\" instead of attr=\"{{ expr }}\" on components:\n".implode("\n", $offenders));
    }

    private static function componentName(string $tag): string
    {
        preg_match('/^<x-([A-Za-z0-9_.:-]+)/', $tag, $m);

        return $m[1];
    }

    /** @return list<string> the kebab-case attribute names the component declares in @props (empty when unknown) */
    private static function propsOf(string $tag): array
    {
        $name = self::componentName($tag);
        foreach (ViewRoots::all() as $root) {
            $file = $root.'/components/'.str_replace('.', '/', $name).'.blade.php';
            if (! is_file($file)) {
                continue;
            }
            if (! preg_match('/@props\(\[(.*?)\]\)/s', File::get($file), $m)) {
                return [];
            }
            preg_match_all('/(?:^|[\s,\[])\'([A-Za-z0-9_]+)\'(?=\s*(?:=>|,|\]|$))/', $m[1], $keys);

            return array_map(fn (string $k) => strtolower(preg_replace('/([A-Z])/', '-$1', $k)), $keys[1]);
        }

        return [];
    }
}
