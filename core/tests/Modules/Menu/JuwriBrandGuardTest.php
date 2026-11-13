<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * P1-01: the product is juwri (JUWRI LTD, juwri.com). The upstream product name
 * (the base product's name) and the removed bought dashboard theme (Lamar, J9) must not
 * come back anywhere in the repository. Allowed exceptions, each listed in
 * the rename map:
 *  - deprecated aliases that keep stored browser settings / old callers working
 *    (ALIASES below — stripped from a line before it is checked);
 *  - the licensor/upstream name in provenance and rule files (EXCLUDED_FILES);
 *  - migrations (replaced wholesale by P1-03) and rev/ (history).
 */
class JuwriBrandGuardTest extends TestCase
{
    /** Upstream product name in any spelling, and the bought theme's name as a word or identifier prefix. */
    private const PATTERNS = [
        '/menu[\s_-]?bar[\s_-]?code/i',
        '/(?<![A-Za-z])[Ll]amar(?![a-z])/',
        '/LAMAR/',
    ];

    /** Deprecated names kept on purpose (rename-map `alias_kept` = yes, plus kept rows). */
    private const ALIASES = [
        'window.Lamar',
        'window.lamarConfirm',
        'window.lamarPrompt',
        "'lamar-theme'",
        "'lamar.orders.sound'",
        "'lamar.sidebar'",
        "'lamar.nav.tab'",
        "'lamar_nav_recent'",
        "'lamar_orders_'",
        '"lamar_orders_*"',
        "'lamar.card.usage'",
        "'lamar.promo.reseller'",
        "\$name === 'lamar'",
        "'app.theme' => 'lamar'",
        'name `lamar`',
        'value "lamar"',
    ];

    /** Only inside tests: negative assertions that the bought theme's folders never return. */
    private const TEST_ALIASES = ['templates/lamar'];

    /** Repo-relative directories scanned (missing ones are skipped). */
    private const DIRS = [
        'core/app', 'core/bootstrap', 'core/config', 'core/routes', 'core/resources', 'core/lang',
        'core/database/seeders', 'core/database/factories', 'core/plugins', 'core/tests',
        'assets', 'docs', 'deploy', 'desktop', 'kiosk', 'driver-app', 'mobile', 'integrations',
        'storefront-starter', 'tools',
    ];

    /** Repo-relative single files scanned. */
    private const FILES = [
        'core/.env.example', 'core/phpunit.xml', 'core/composer.json', 'core/package.json', 'core/README.md',
        'README.md', 'index.php', 'router.php', 'sw-menu.js', 'robots.txt',
        '.htaccess', 'web.config', 'offline.html',
    ];

    /** Provenance / upstream-rule files where the licensor's name is the point. */
    private const EXCLUDED_FILES = [
        'core/tests/Modules/Menu/JuwriBrandGuardTest.php',
        'core/AGENTS.md',
    ];

    private const EXCLUDED_PREFIXES = [
        'core/.ai/',
        'core/database/migrations/',
        'assets/global/pdfjs/',
        'rev/',
    ];

    private const EXTENSIONS = [
        'php', 'js', 'mjs', 'cjs', 'ts', 'json', 'css', 'md', 'html', 'txt', 'xml', 'yml', 'yaml',
        'kts', 'kt', 'swift', 'strings', 'plist', 'pbxproj', 'xcconfig', 'properties', 'pro', 'gradle',
        'liquid', 'neon', 'sh', 'entitlements', 'svg', 'example', 'conf',
    ];

    public function test_no_upstream_brand_or_bought_theme_name_outside_the_rename_map_aliases(): void
    {
        $repo = realpath(base_path('..'));
        $this->assertNotFalse($repo);

        $paths = [];
        foreach (self::FILES as $file) {
            if (is_file($repo.'/'.$file)) {
                $paths[] = $file;
            }
        }
        $dirs = array_values(array_filter(self::DIRS, fn ($d) => is_dir($repo.'/'.$d)));
        $finder = (new Finder)->files()->in(array_map(fn ($d) => $repo.'/'.$d, $dirs))
            ->ignoreDotFiles(false)->ignoreVCS(true)
            ->exclude(['vendor', 'node_modules', 'dist', 'build', 'storage', 'Pods', '.gradle']);
        foreach ($finder as $file) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, self::EXTENSIONS, true)) {
                $paths[] = ltrim(str_replace($repo, '', $file->getPathname()), '/');
            }
        }

        $hits = [];
        foreach ($paths as $rel) {
            if (in_array($rel, self::EXCLUDED_FILES, true)) {
                continue;
            }
            foreach (self::EXCLUDED_PREFIXES as $prefix) {
                if (str_starts_with($rel, $prefix)) {
                    continue 2;
                }
            }
            $aliases = str_starts_with($rel, 'core/tests/') ? [...self::ALIASES, ...self::TEST_ALIASES] : self::ALIASES;
            foreach (preg_split('/\R/', (string) file_get_contents($repo.'/'.$rel)) as $i => $line) {
                $line = str_replace($aliases, '', $line);
                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $hits[] = $rel.':'.($i + 1).': '.trim(mb_substr($line, 0, 140));
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $hits, "Upstream brand or bought-theme name found (rename it, or add an alias to the rename map and this test):\n".implode("\n", $hits));
    }

    public function test_the_patterns_catch_a_planted_forbidden_word(): void
    {
        // Spelled out at run time: this file must not contain the words it forbids.
        foreach (['Menu'.'BarCode', 'menu_'.'bar_code', 'Menu '.'Barcode'] as $planted) {
            $this->assertSame(1, preg_match(self::PATTERNS[0], "x {$planted} y"), $planted);
        }
        $this->assertSame(1, preg_match(self::PATTERNS[1], 'La'.'mar theme'));
        $this->assertSame(0, preg_match(self::PATTERNS[0], 'a barcode menu for juwri'));
    }

    public function test_the_rename_is_visible_in_config(): void
    {
        $this->assertSame('juwri', config('appinfo.name'));
        $this->assertSame('https://juwri.com', config('appinfo.url'));
        $this->assertSame('JuwriApp', config('mobile_app.user_agent_token'));
        $this->assertSame('juwri', active_theme_name());
    }
}
