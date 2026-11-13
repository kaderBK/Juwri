<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Storefront\ThemePackageInstaller;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * P2-5: the theme package format — fixed layout, closed extension whitelist
 * (no PHP anywhere, no JS in v1), size caps, dangerous-markup scans, manifest
 * rules, and the immutable-version install path.
 */
class ThemePackageTest extends TestCase
{
    use RefreshDatabase; // installer writes the P2-6 theme ledger

    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zipPath = storage_path('app/test-theme-package.zip');
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        File::deleteDirectory(storage_path('app/marketplace-themes/pkgtest'));
        File::deleteDirectory(public_path('assets/marketplace-themes/pkgtest'));
        parent::tearDown();
    }

    /**
     * Build a theme zip. $mutate edits the default file map (path => content);
     * a null value removes the file.
     */
    private function makeZip(array $mutate = []): string
    {
        $files = [
            'theme.json' => json_encode(['name' => 'Pkg Test', 'slug' => 'pkgtest', 'version' => '1.0.0', 'author' => 'T']),
            'templates/index.liquid' => '<h1>{{ restaurant.title | escape }}</h1>',
            'sections/hero.liquid' => '<p>{{ section.settings.heading }}</p>{% schema %}{"name":"Hero","settings":[]}{% endschema %}',
            'config/settings_schema.json' => '[]',
            'locales/en.json' => '{}',
            'assets/css/style.css' => '.x{color:red}',
            'preview.png' => 'PNGDATA',
        ];
        foreach ($mutate as $path => $content) {
            if ($content === null) {
                unset($files[$path]);
            } else {
                $files[$path] = $content;
            }
        }

        @unlink($this->zipPath);
        $zip = new ZipArchive();
        $zip->open($this->zipPath, ZipArchive::CREATE);
        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();

        return $this->zipPath;
    }

    public function test_valid_package_passes_and_returns_manifest(): void
    {
        $manifest = ThemePackageValidator::assertSafe($this->makeZip());
        $this->assertSame('pkgtest', $manifest['slug']);
        $this->assertSame('1.0.0', $manifest['version']);
    }

    public function test_php_is_rejected_anywhere(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File type not allowed');
        ThemePackageValidator::assertSafe($this->makeZip(['assets/evil.php' => '<?php system($_GET[1]);']));
    }

    public function test_js_is_rejected_in_v1(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File type not allowed');
        ThemePackageValidator::assertSafe($this->makeZip(['assets/app.js' => 'alert(1)']));
    }

    public function test_path_traversal_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('illegal path');
        ThemePackageValidator::assertSafe($this->makeZip(['../escape.css' => 'x']));
    }

    public function test_missing_manifest_or_entrypoint_is_rejected(): void
    {
        try {
            ThemePackageValidator::assertSafe($this->makeZip(['theme.json' => null]));
            $this->fail('missing manifest accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('theme.json', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('templates/index');
        ThemePackageValidator::assertSafe($this->makeZip(['templates/index.liquid' => null]));
    }

    public function test_script_markup_is_rejected_in_liquid_and_svg(): void
    {
        foreach ([
            ['sections/hero.liquid' => '<script>alert(1)</script>'],
            ['sections/hero.liquid' => '<button onclick="x()">hi</button>'],
            ['sections/hero.liquid' => '<a href="javascript:alert(1)">x</a>'],
            ['assets/img/logo.svg' => '<svg><script>alert(1)</script></svg>'],
            ['assets/img/logo.svg' => '<svg><foreignObject/></svg>'],
            // Security (F4): browser-accepted spellings the plain regexes missed.
            ['assets/img/logo.svg' => '<svg/onload=alert(1)>'],
            ['sections/hero.liquid' => '<a href="jav&#x61;script:alert(1)">x</a>'],
            ['sections/hero.liquid' => '<a href="jav&#97script:alert(1)">x</a>'],
            ['sections/hero.liquid' => "<a href=\"java\tscript:alert(1)\">x</a>"],
        ] as $mutation) {
            try {
                ThemePackageValidator::assertSafe($this->makeZip($mutation));
                $this->fail('dangerous markup accepted: '.json_encode($mutation));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }
    }

    public function test_oversized_entry_and_unknown_root_are_rejected(): void
    {
        try {
            ThemePackageValidator::assertSafe(
                $this->makeZip(['assets/big.css' => str_repeat('a', ThemePackageValidator::MAX_ENTRY_BYTES + 1)])
            );
            $this->fail('oversized entry accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('oversized', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected directory');
        ThemePackageValidator::assertSafe($this->makeZip(['vendor/lib.css' => 'x']));
    }

    public function test_bad_manifest_slug_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('safe slug');
        ThemePackageValidator::assertSafe($this->makeZip([
            'theme.json' => json_encode(['name' => 'X', 'slug' => '../evil', 'version' => '1']),
        ]));
    }

    public function test_install_lands_in_store_and_public_assets_and_is_immutable(): void
    {
        $theme = new ThemePackageInstaller()->install($this->makeZip());

        $this->assertSame('pkgtest', $theme->slug);
        $this->assertFileExists(storage_path('app/marketplace-themes/pkgtest/1.0.0/templates/index.liquid'));
        $this->assertFileExists(public_path('assets/marketplace-themes/pkgtest/1.0.0/css/style.css'));
        $this->assertFileExists(public_path('assets/marketplace-themes/pkgtest/1.0.0/preview.png'));
        // theme_asset map picks up the published asset.
        $this->assertArrayHasKey('css/style.css', $theme->assetMap());

        // Versions are immutable: same slug@version refuses to install again.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        new ThemePackageInstaller()->install($this->makeZip());
    }
}
