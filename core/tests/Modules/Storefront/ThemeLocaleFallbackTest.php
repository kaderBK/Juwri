<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * B4 (P2-2b follow-up): the `t` filter's theme-bundled locale fallback —
 * platform keys win, theme locales fill the gaps, keys never write anywhere.
 */
class ThemeLocaleFallbackTest extends TestCase
{
    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory(), new RenderClient());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/loctest'));
        parent::tearDown();
    }

    public function test_theme_locale_fills_gaps_but_platform_keys_win(): void
    {
        App::setLocale('en');
        $registers = ['theme_locale_map' => [
            'Frobnicate' => 'Frobnicate (theme)',
            'new_menu' => 'SHOULD NOT WIN', // 'new_menu' exists in platform lang files
        ]];

        // Platform key wins.
        $this->assertSame(
            (string) trans('lang.new_menu', [], 'en'),
            $this->renderer()->render("{{ 'new_menu' | t }}", [], 'live', $registers)
        );
        // Theme locale fills a platform gap.
        $this->assertSame(
            'Frobnicate (theme)',
            $this->renderer()->render("{{ 'Frobnicate' | t }}", [], 'live', $registers)
        );
        // Unknown everywhere → the key itself (and nothing is written).
        $before = File::get(base_path('lang/en/lang.php'));
        $this->assertSame(
            'b4_missing_probe',
            $this->renderer()->render("{{ 'b4_missing_probe' | t }}", [], 'live', $registers)
        );
        $this->assertSame($before, File::get(base_path('lang/en/lang.php')), 'still read-only');
        // No register at all → key.
        $this->assertSame('Frobnicate', $this->renderer()->render("{{ 'Frobnicate' | t }}", []));
    }

    public function test_marketplace_theme_reads_its_locales_safely(): void
    {
        $dir = storage_path('app/marketplace-themes/loctest/1/');
        File::ensureDirectoryExists($dir.'templates');
        File::ensureDirectoryExists($dir.'locales');
        File::put($dir.'templates/index.liquid', 'x');
        File::put($dir.'locales/ar.json', json_encode(['Cart' => 'السلة', 'junk' => ['not-a-string']]));

        $theme = MarketplaceThemeResolver::resolve('mp:loctest@1', gate: false);

        $this->assertSame(['Cart' => 'السلة'], $theme->locales('ar'), 'strings only');
        $this->assertSame([], $theme->locales('xx'), 'missing locale → empty');
        $this->assertSame([], $theme->locales('../evil'), 'bad code rejected');
    }
}
