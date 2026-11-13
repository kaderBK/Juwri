<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P4-2: the theme-check-equivalent linter — structured author-facing report;
 * errors fail a submission. The in-repo starter theme must lint clean (the
 * "docs alone" acceptance from P2-7 rides on it).
 */
class ThemeLinterTest extends TestCase
{
    private const BASE = 'app/marketplace-themes/linttest';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    /** @param array<string,string> $files rel path → content */
    private function writeTheme(array $files): \Juwri\Modules\Storefront\Storefront\MarketplaceTheme
    {
        $dir = storage_path(self::BASE.'/1');
        File::deleteDirectory(storage_path(self::BASE));
        foreach ($files as $rel => $content) {
            File::ensureDirectoryExists(dirname($dir.'/'.$rel));
            File::put($dir.'/'.$rel, $content);
        }

        return MarketplaceThemeResolver::resolve('mp:linttest@1', gate: false);
    }

    private function rules(array $report, string $bucket = 'errors'): array
    {
        return array_values(array_unique(array_column($report[$bucket], 'rule')));
    }

    public function test_the_starter_theme_lints_clean(): void
    {
        $starter = MarketplaceThemeResolver::resolve('mp:starter@1', gate: false);
        $report = new ThemeLinter()->lint($starter);

        $this->assertTrue($report['passed'], 'starter theme must pass its own linter: '.json_encode($report['errors']));
        $this->assertSame([], $report['errors']);
    }

    public function test_syntax_unknown_filter_and_missing_snippet_are_errors(): void
    {
        $theme = $this->writeTheme([
            'templates/index.json' => json_encode(['sections' => ['a' => ['type' => 'hero']], 'order' => ['a']]),
            'sections/hero.liquid' => "{% if broken %}\n{{ restaurant.title | sketchy_filter }}\n{% render 'ghost' %}",
        ]);

        $report = new ThemeLinter()->lint($theme);

        $this->assertFalse($report['passed']);
        $rules = $this->rules($report);
        $this->assertContains('liquid-syntax', $rules);   // unclosed if
        $this->assertContains('unknown-filter', $rules);  // sketchy_filter
        $this->assertContains('missing-snippet', $rules); // ghost
    }

    public function test_missing_section_external_url_and_limits_are_errors(): void
    {
        $blocks = [];
        for ($i = 0; $i <= 50; $i++) {
            $blocks['b'.$i] = ['type' => 'card'];
        }
        $theme = $this->writeTheme([
            'templates/index.json' => json_encode([
                'sections' => [
                    'a' => ['type' => 'ghost-section'],
                    'b' => ['type' => 'hero', 'blocks' => $blocks],
                ],
                'order' => ['a', 'b'],
            ]),
            'sections/hero.liquid' => '<img src="https://cdn.evil.test/x.png">',
            'assets/css/style.css' => '@import url("https://fonts.example.com/f.css");',
        ]);

        $report = new ThemeLinter()->lint($theme);

        $rules = $this->rules($report);
        $this->assertContains('missing-section', $rules);
        $this->assertContains('limit-exceeded', $rules);
        $this->assertContains('external-url', $rules);
        // Both the liquid and the css external URLs flagged.
        $externalFiles = array_column(array_filter($report['errors'], fn ($e) => $e['rule'] === 'external-url'), 'file');
        $this->assertContains('sections/hero.liquid', $externalFiles);
        $this->assertContains('assets/style.css', $externalFiles);
    }

    public function test_unused_assign_is_a_warning_not_an_error(): void
    {
        $theme = $this->writeTheme([
            'templates/index.json' => json_encode(['sections' => ['a' => ['type' => 'hero']], 'order' => ['a']]),
            'sections/hero.liquid' => "{% assign ghost = 1 %}{% assign used = 2 %}<p>{{ used }}</p>",
        ]);

        $report = new ThemeLinter()->lint($theme);

        $this->assertTrue($report['passed'], 'warnings alone must not fail: '.json_encode($report['errors']));
        $warned = array_column($report['warnings'], 'message');
        $this->assertTrue((bool) preg_grep('/ghost/', $warned), 'ghost flagged');
        $this->assertFalse((bool) preg_grep("/'used'/", $warned), 'used var not flagged');
    }
}
