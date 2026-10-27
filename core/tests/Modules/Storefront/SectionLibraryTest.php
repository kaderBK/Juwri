<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Theme Studio B0.1: themes pinned to the platform section library
 * (`"library"` in theme.json) resolve sections, snippets and locales from it,
 * with the theme's own files winning on a clash. Unpinned themes are unaffected.
 */
class SectionLibraryTest extends TestCase
{
    private const THEMES = 'app/marketplace-themes/libtest';

    private string $libBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->libBase = storage_path('framework/testing/liquid-library');
        config(['platform.liquid_library.path' => $this->libBase]);

        $lib = $this->libBase.'/1';
        File::ensureDirectoryExists($lib.'/sections');
        File::ensureDirectoryExists($lib.'/snippets');
        File::ensureDirectoryExists($lib.'/locales');
        File::put($lib.'/sections/lib-hero.liquid',
            '<h1 class="lib">{{ section.settings.heading }}</h1>{% render \'badge\' %}'."\n".
            '{% schema %}{"name":"Hero","settings":[{"id":"heading","type":"text","default":"Library Hero"}]}{% endschema %}');
        File::put($lib.'/sections/lib-menu.liquid',
            '{% for c in menu.categories %}{% for item in c.items %}<a data-mb-open="{{ item.id }}" data-mb-add="{{ item.id }}">{{ item.price | money }}</a>{% endfor %}{% endfor %}{% commerce %}'."\n".
            '{% schema %}{"name":"Menu"}{% endschema %}');
        File::put($lib.'/sections/shared.liquid', '<p>library shared</p>');
        File::put($lib.'/snippets/badge.liquid', '<span class="badge">lib-badge</span>');
        File::put($lib.'/snippets/pill.liquid', '<i>lib-pill</i>');
        File::put($lib.'/locales/en.json', json_encode(['cta' => 'Library CTA', 'hello' => 'Library Hello']));
        File::ensureDirectoryExists($lib.'/locales/en');
        File::put($lib.'/locales/en/b1b.json', json_encode(['menu' => 'Library Menu']));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::THEMES));
        File::deleteDirectory($this->libBase);
        parent::tearDown();
    }

    /** @param array<string,string> $files rel path → contents */
    private function writeTheme(string $version, array $files): MarketplaceTheme
    {
        $dir = storage_path(self::THEMES.'/'.$version);
        foreach ($files as $rel => $contents) {
            File::ensureDirectoryExists(dirname($dir.'/'.$rel));
            File::put($dir.'/'.$rel, $contents);
        }

        return MarketplaceThemeResolver::resolve('mp:libtest@'.$version);
    }

    private function template(array $types): string
    {
        $sections = [];
        foreach ($types as $i => $type) {
            $sections['s'.$i] = ['type' => $type];
        }

        return json_encode(['sections' => $sections, 'order' => array_keys($sections)]);
    }

    private function engine(): SectionedTemplate
    {
        return new SectionedTemplate(new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient));
    }

    public function test_pinned_theme_renders_library_sections_and_snippets(): void
    {
        $theme = $this->writeTheme('1', [
            'theme.json' => json_encode(['name' => 'Lib', 'slug' => 'libtest', 'version' => '1', 'library' => '1']),
            'templates/index.json' => $this->template(['lib-hero', 'shared']),
        ]);

        $this->assertSame('1', $theme->library()?->version);

        $out = $this->engine()->render($theme, [], [], [], 'upload');

        $this->assertStringContainsString('<h1 class="lib">Library Hero</h1>', $out);
        $this->assertStringContainsString('lib-badge', $out, 'library snippet rendered from a library section');
        $this->assertStringContainsString('library shared', $out);
    }

    public function test_theme_files_override_library_files_of_the_same_name(): void
    {
        $theme = $this->writeTheme('1', [
            'theme.json' => json_encode(['name' => 'Lib', 'slug' => 'libtest', 'version' => '1', 'library' => 1]),
            'templates/index.json' => $this->template(['lib-hero', 'shared']),
            'sections/shared.liquid' => '<p>theme shared</p>',
            'snippets/badge.liquid' => '<span>theme-badge</span>',
            'locales/en.json' => json_encode(['cta' => 'Theme CTA']),
        ]);

        $out = $this->engine()->render($theme, [], [], [], 'upload');

        $this->assertStringContainsString('theme shared', $out);
        $this->assertStringNotContainsString('library shared', $out);
        $this->assertStringContainsString('theme-badge', $out, 'theme snippet wins inside a library section');

        $snippets = $theme->snippets();
        $this->assertSame('<i>lib-pill</i>', $snippets['pill'], 'non-clashing library snippets still available');

        $this->assertSame(
            ['cta' => 'Theme CTA', 'hello' => 'Library Hello', 'menu' => 'Library Menu'],
            $theme->locales('en'),
            'theme strings override library strings; the rest fall through'
        );
    }

    public function test_unpinned_theme_does_not_see_the_library(): void
    {
        $theme = $this->writeTheme('1', [
            'theme.json' => json_encode(['name' => 'Lib', 'slug' => 'libtest', 'version' => '1']),
            'templates/index.json' => $this->template(['lib-hero']),
        ]);

        $this->assertNull($theme->library());
        $this->assertNull($theme->sectionSource('lib-hero'));
        $this->assertSame([], $theme->snippets());
        $this->assertSame('', $this->engine()->render($theme, []), 'unknown section skipped in live mode');
    }

    public function test_unsafe_or_unknown_library_versions_resolve_to_nothing(): void
    {
        $this->assertNull(SectionLibrary::forVersion('../1'));
        $this->assertNull(SectionLibrary::forVersion('1/../../x'));
        $this->assertNull(SectionLibrary::forVersion(['1']));
        $this->assertNull(SectionLibrary::forVersion('9'), 'not shipped');
        $this->assertNotNull(SectionLibrary::forVersion(1));
        $this->assertNull(new SectionLibrary('1', $this->libBase.'/1')->sectionSource('../x'));
        $this->assertSame(['lib-hero', 'lib-menu', 'shared'], SectionLibrary::forVersion('1')->sectionTypes());
    }

    public function test_manifest_validation_checks_the_library_pin(): void
    {
        $base = ['name' => 'Lib', 'slug' => 'libtest', 'version' => '1'];

        $this->assertSame([], ThemePackageValidator::manifestIssues(json_encode($base + ['library' => '1'])));
        $this->assertSame([], ThemePackageValidator::manifestIssues(json_encode($base)), 'library is optional');
        $this->assertCount(1, ThemePackageValidator::manifestIssues(json_encode($base + ['library' => '../1'])));
        $this->assertCount(1, ThemePackageValidator::manifestIssues(json_encode($base + ['library' => '7'])));
    }

    public function test_linter_resolves_library_sections_and_feature_wiring(): void
    {
        $manifest = json_encode(['name' => 'Lib', 'slug' => 'libtest', 'version' => '1', 'library' => '1']);
        $index = $this->template(['lib-hero', 'lib-menu']);

        $theme = $this->writeTheme('1', ['theme.json' => $manifest, 'templates/index.json' => $index]);
        $report = (new ThemeLinter)->lint($theme);

        $this->assertTrue($report['passed'], json_encode($report['errors']));
        $rules = array_column($report['warnings'], 'rule');
        $this->assertNotContains('commerce-tag-missing', $rules, 'commerce tag found in the library section');
        $this->assertNotContains('cart-add-missing', $rules);

        $mapReport = (new ThemeLinter)->lintFiles(['theme.json' => $manifest, 'templates/index.json' => $index]);
        $this->assertTrue($mapReport['passed'], json_encode($mapReport['errors']));
        $this->assertNotContains('commerce-tag-missing', array_column($mapReport['warnings'], 'rule'));

        // Without the pin the same template is missing both sections.
        $unpinned = (new ThemeLinter)->lintFiles(['templates/index.json' => $index]);
        $this->assertSame(['missing-section', 'missing-section'], array_column($unpinned['errors'], 'rule'));
    }
}
