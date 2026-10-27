<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * WI-04: the base product's identity (its product names, vendor, marketplace,
 * author links, support desks, the self-updater)
 * stays out of the app. The admin views (WI-10) and the bought theme (J9) (WI-16)
 * are rebuilt, so nothing is pending any more.
 */
class BaseIdentityTest extends TestCase
{
    // Built at run time so this file does not itself spell the names it forbids.
    private const PATTERN = '/quick'.'[\s_-]?qr|quick'.'[\s_-]?cms|by'.'lancer|code[\s_-]?canyon|envato/i';

    /** Paths (relative to core/) still owned by a later rebuild. */
    private const PENDING = [];

    public function test_no_base_identity_in_app_routes_config_views_or_lang(): void
    {
        $finder = (new Finder)->files()->in(array_map('base_path', ['app', 'config', 'routes', 'resources', 'lang', 'database/seeders', 'database/migrations']))
            ->name(['*.php', '*.js', '*.json', '*.liquid', '*.md', '*.css']);

        $hits = [];
        foreach ($finder as $file) {
            $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            foreach (self::PENDING as $prefix) {
                if (str_starts_with($rel, $prefix)) {
                    continue 2;
                }
            }
            foreach (preg_split('/\R/', $file->getContents()) as $i => $line) {
                if (! preg_match(self::PATTERN, $line)) {
                    continue;
                }
                $hits[] = $rel.':'.($i + 1).': '.trim(mb_substr($line, 0, 120));
            }
        }

        $this->assertSame([], $hits, "Base product identity found:\n".implode("\n", $hits));
    }

    public function test_the_guard_pattern_catches_a_planted_forbidden_word(): void
    {
        foreach (['Quick'.'QR', 'quick_'.'qr', 'Quick '.'CMS', 'Byl'.'ancer', 'Code'.'Canyon', 'Env'.'ato'] as $planted) {
            $this->assertSame(1, preg_match(self::PATTERN, "x {$planted} y"), $planted);
        }
        $this->assertSame(0, preg_match(self::PATTERN, 'juwri quick order'));
    }

    public function test_the_base_self_updater_is_gone(): void
    {
        foreach (['update', 'update.run', 'update.complete'] as $name) {
            $this->assertFalse(app('router')->has($name), "route [{$name}] still registered");
        }
        $this->assertFileDoesNotExist(app_path('Http/Controllers/UpdateController.php'));
        $this->assertDirectoryDoesNotExist(database_path('upgrades'));
    }

    public function test_demo_bar_names_the_product_not_the_base_vendor(): void
    {
        config(['app.demo_mode' => false]);
        $this->assertSame('', trim(Blade::render('<x-demo-frame />')));

        config(['app.demo_mode' => true, 'app.name' => 'DemoBrand', 'appinfo.buy_now_url' => 'https://buy.example.test']);
        // The bar prefers the stored site title; a persistent test database (MySQL/PostgreSQL) may carry one.
        $this->shareLayoutGlobals(['site_title' => null]);
        $html = Blade::render('<x-demo-frame />');

        $this->assertStringContainsString('class="demo-bar"', $html);
        $this->assertStringContainsString('DemoBrand', $html);
        $this->assertStringContainsString('href="https://buy.example.test"', $html);
        $this->assertDoesNotMatchRegularExpression(self::PATTERN, $html);
        $this->assertStringNotContainsString('ticksy', $html);
    }
}
