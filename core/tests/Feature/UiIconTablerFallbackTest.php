<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-ui.icon>: kit icons win; any other name resolves to a vendored Tabler Icons
 * SVG (assets/vendor/tabler-icons, MIT) inlined with currentColor; unknown or
 * unsafe names render nothing. Every icon named in the FA → icon map must render.
 */
class UiIconTablerFallbackTest extends TestCase
{
    private function icon(string $name, string $extra = ''): string
    {
        return trim(Blade::render('<x-ui.icon name="'.$name.'" '.$extra.' />'));
    }

    public function test_kit_icon_is_drawn_before_tabler(): void
    {
        // "search" exists both in the kit and in Tabler; the kit path must be used.
        $svg = $this->icon('search');

        $this->assertStringContainsString('<circle cx="11" cy="11" r="6.5"/>', $svg);
        $this->assertStringContainsString('stroke-width="1.9"', $svg);
    }

    public function test_unknown_kit_name_falls_back_to_tabler_outline(): void
    {
        $svg = $this->icon('lock', 'size="16" class="x-lock"');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
        $this->assertStringContainsString('fill="none"', $svg);
        $this->assertStringContainsString('width="16"', $svg);
        $this->assertStringContainsString('class="x-lock"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('<path d="M8 11v-4a4 4 0 1 1 8 0v4"', $svg);
        // Tabler's invisible bounding-box path and its own class list are dropped.
        $this->assertStringNotContainsString('M0 0h24v24H0z', $svg);
        $this->assertStringNotContainsString('icon-tabler', $svg);
        $this->assertSame(1, substr_count($svg, '<svg'));
    }

    public function test_filled_suffix_uses_the_filled_variant(): void
    {
        $svg = $this->icon('star-filled');

        $this->assertStringContainsString('fill="currentColor"', $svg);
        $this->assertStringContainsString('stroke="none"', $svg);
        $this->assertStringContainsString('<path d="M8.243 7.34', $svg);
    }

    public function test_flip_adds_the_rtl_helper_to_tabler_icons(): void
    {
        $this->assertStringContainsString('ui-flip-rtl', $this->icon('arrow-left', 'flip'));
    }

    public function test_unknown_and_unsafe_names_render_nothing(): void
    {
        foreach (['no-such-icon-anywhere', '../LICENSE', 'outline/lock', 'Lock', 'lock.svg', '-filled', ''] as $name) {
            $this->assertSame('', $this->icon($name), "name [{$name}] should render nothing");
        }
    }

    public function test_every_icon_in_the_fa_map_renders(): void
    {
        $csv = base_path('tests/Fixtures/icon-map.csv');
        $this->assertFileExists($csv);

        $rows = array_map('str_getcsv', array_slice(file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1));
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            [$fa, , $icon] = $row;
            $this->assertStringStartsWith('<svg', $this->icon($icon), "{$fa} → {$icon} does not render");
        }
    }

    public function test_vendored_set_ships_its_licence(): void
    {
        $dir = base_path('../assets/vendor/tabler-icons');

        $this->assertFileExists($dir.'/LICENSE');
        $this->assertStringContainsString('MIT License', (string) file_get_contents($dir.'/LICENSE'));
    }

    /**
     * P6-36: a worker must not grow with every icon it draws. A closure literal in a compiled view keeps ~250 bytes per
     * render on PHP 8.4 (1,000 renders = 250 KB before the fix, ~0 after); the view must stay free of closures.
     */
    public function test_rendering_an_icon_does_not_grow_memory(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\bfn\s*\(|\bfunction\s*\(/', (string) file_get_contents(resource_path('views/components/ui/icon.blade.php')));

        foreach (range(1, 300) as $i) {
            $this->icon($i % 2 ? 'flame' : 'lock', 'size="14"');
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        foreach (range(1, 1000) as $i) {
            $this->icon($i % 2 ? 'flame' : 'lock', 'size="14"');
        }
        gc_collect_cycles();

        $this->assertLessThan(100 * 1024, memory_get_usage() - $before, 'rendering <x-ui.icon> keeps memory');
    }
}
