<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Theme;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use App\Models\ThemeVersion;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\ThemePackageInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * P2-6: the versioned theme store — DB ledger (themes/theme_versions) with
 * immutable published versions, resolver gating on published status, merchant
 * settings surviving updates, and the theme-scoped {% render %} snippet map.
 */
class ThemeVersioningTest extends TestCase
{
    use RefreshDatabase;

    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1); // P1-04 5b: fixture store ids must be real stores
        $this->zipPath = storage_path('app/test-theme-version.zip');
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        File::deleteDirectory(storage_path('app/marketplace-themes/vertest'));
        File::deleteDirectory(public_path('assets/marketplace-themes/vertest'));
        parent::tearDown();
    }

    private function makeZip(string $version = '1.0.0'): string
    {
        @unlink($this->zipPath);
        $zip = new ZipArchive();
        $zip->open($this->zipPath, ZipArchive::CREATE);
        $zip->addFromString('theme.json', json_encode(['name' => 'Ver Test', 'slug' => 'vertest', 'version' => $version]));
        $zip->addFromString('templates/index.liquid', 'V'.$version.' {% render "card", label: "X" %}');
        $zip->addFromString('snippets/card.liquid', '<b>card:{{ label }}</b>');
        $zip->close();

        return $this->zipPath;
    }

    public function test_install_writes_the_ledger_and_gate_blocks_unpublished(): void
    {
        /* Hash the archive we actually install, BEFORE installing it. The previous
           version called makeZip() a second time to derive the expectation, but a
           rebuilt zip is not byte-identical: ZipArchive stamps each entry with its
           mtime, so a rebuild that crosses a second boundary hashes differently.
           That passed locally (both writes land in the same second) and failed
           intermittently on the slower CI runner. */
        $zipPath  = $this->makeZip();
        $expected = hash_file('sha256', $zipPath);

        new ThemePackageInstaller()->install($zipPath);

        $theme = Theme::where('slug', 'vertest')->first();
        $this->assertNotNull($theme);
        $version = $theme->versions()->first();
        $this->assertSame('1.0.0', $version->semver);
        $this->assertSame('review', $version->status);
        $this->assertSame($expected, $version->checksum);

        // Storefront gate: review versions do not resolve; ungated (internal) does.
        $this->assertNull(MarketplaceThemeResolver::resolve('mp:vertest@1.0.0'));
        $this->assertNotNull(MarketplaceThemeResolver::resolve('mp:vertest@1.0.0', gate: false));

        $version->update(['status' => 'published']);
        $this->assertNotNull(MarketplaceThemeResolver::resolve('mp:vertest@1.0.0'));
    }

    public function test_published_versions_are_immutable(): void
    {
        new ThemePackageInstaller()->install($this->makeZip());
        $version = ThemeVersion::first();
        $version->update(['status' => 'published']);

        $version->update(['status' => 'delisted']); // status may move
        $version->update(['status' => 'published']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $version->update(['checksum' => str_repeat('0', 64)]);
    }

    public function test_snippet_render_works_and_traversal_is_impossible(): void
    {
        new ThemePackageInstaller()->install($this->makeZip());
        $theme = MarketplaceThemeResolver::resolve('mp:vertest@1.0.0', gate: false);
        $renderer = new LiquidRenderer(new LiquidEnvironmentFactory(), new RenderClient());

        $out = $renderer->render($theme->templateSource(), [], 'live', [], $theme->snippets());
        $this->assertStringContainsString('<b>card:X</b>', $out, '{% render %} resolves via the snippet map');

        // A snippet name that looks like a path can never resolve (key lookup only).
        $this->expectException(RenderFailedException::class);
        $renderer->render("{% render '../../etc/passwd' %}", [], 'upload', [], $theme->snippets());
    }

    public function test_new_version_leaves_old_files_and_merchant_settings_untouched(): void
    {
        $installer = new ThemePackageInstaller();
        $installer->install($this->makeZip('1.0.0'));

        // Merchant customizes (versionless key — the P2-4 contract).
        ThemeSetting::create(['store_id' => 1, 'template' => 'mp:vertest', 'settings' => ['sections.hero.heading' => 'Mine']]);
        $v1Index = storage_path('app/marketplace-themes/vertest/1.0.0/templates/index.liquid');
        $v1Content = File::get($v1Index);

        $installer->install($this->makeZip('2.0.0'));

        $this->assertSame($v1Content, File::get($v1Index), 'v1 bundle untouched');
        $this->assertSame(2, ThemeVersion::count());
        $this->assertSame('Mine', ThemeSetting::where('template', 'mp:vertest')->first()->settings['sections.hero.heading'], 'merchant settings survive');
    }
}
