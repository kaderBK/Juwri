<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * J4 / WI-13b: Font Awesome Pro (paid) is gone. Icons come from the kit
 * (`<x-ui.icon>`, falling back to vendored Tabler SVGs); nothing may load the
 * FA stylesheet or print FA classes again.
 */
class FontAwesomeRemovedTest extends TestCase
{
    private function root(): string
    {
        return dirname(base_path());
    }

    public function test_font_awesome_files_are_not_shipped(): void
    {
        $this->assertFileDoesNotExist($this->root().'/assets/global/fonts/css/fontawesome.css');
        $this->assertSame([], glob($this->root().'/assets/global/fonts/src/fa-*') ?: []);
    }

    public function test_no_view_script_or_php_uses_font_awesome(): void
    {
        $finder = (new Finder)->files()
            // P1-13: + the code and views of an installed edition package's modules (none in the Community mirror).
            ->in(array_values(array_filter([base_path('app'), base_path('resources/views'), ...\Tests\Support\EditionFolders::dirs(), base_path('plugins'), $this->root().'/assets'], 'is_dir')))
            ->exclude(['vendor'])
            ->name(['*.php', '*.js', '*.css', '*.html'])
            ->notName('*.min.js');

        $pattern = '/fontawesome|font awesome|\bfa-(solid|regular|brands|light|thin|duotone)\b|class=["\']fa[srlb]? fa-|<i class=["\']fa[srlb]? /i';
        $hits = [];
        foreach ($finder as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = $file->getRelativePathname().':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $hits);
    }
}
