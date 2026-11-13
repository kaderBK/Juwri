<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\File;
use Juwri\Core\Editions\EditionPackages;
use Tests\TestCase;

/**
 * WI-M17: the repo's non-negotiable rules doc (CONVENTIONS) is cited as normative
 * from shipped source by `§` anchor, but used to live in a completed run pack with
 * no path a reader could find. It now lives at
 * core/resources/docs/internal/conventions.md, indexed from the internal docs
 * README and core/README. These tests pin that home, keep the old run-pack path
 * free of a second copy, and guarantee every `§` anchor cited from core/
 * resolves — so a citation can never dangle. No database needed.
 */
class ConventionsDocTest extends TestCase
{
    private function canonicalPath(): string
    {
        return resource_path('docs/internal/conventions.md');
    }

    private function oldRunPackPath(): string
    {
        return dirname(base_path()).'/rev/improvements-100/CONVENTIONS.md';
    }

    public function test_the_conventions_doc_lives_at_the_canonical_path(): void
    {
        $this->assertFileExists($this->canonicalPath());
    }

    public function test_every_conventions_section_cited_from_core_resolves_to_an_anchor(): void
    {
        $doc = File::get($this->canonicalPath());

        $dirs = [
            base_path('app'),
            base_path('tests'),
            base_path('routes'),
            base_path('resources/views'),
            // P1-13: the installed edition packages' code, views and tests (moved out of app/, resources/views, tests/)
            ...array_column(EditionPackages::all(), 'path'),
        ];

        $dangling = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (File::allFiles($dir) as $file) {
                $contents = @file_get_contents($file->getRealPath());
                if ($contents === false) {
                    continue;
                }
                if (! preg_match_all('/CONVENTIONS\s+§([A-Za-z-]+)/', $contents, $m)) {
                    continue;
                }
                foreach ($m[1] as $token) {
                    if (! str_contains($doc, '§'.$token)) {
                        $dangling[] = $file->getRelativePathname().' cites §'.$token
                            .' which is not defined in '.$this->canonicalPath();
                    }
                }
            }
        }

        $this->assertSame([], $dangling, "Dangling CONVENTIONS citations:\n".implode("\n", $dangling));
    }

    public function test_the_internal_docs_index_links_the_conventions_page(): void
    {
        $this->assertStringContainsString('(conventions.md)', File::get(resource_path('docs/internal/README.md')));
    }

    public function test_the_repository_readme_points_at_the_conventions_doc(): void
    {
        $this->assertStringContainsString('docs/internal/conventions.md', File::get(base_path('README.md')));
    }

    public function test_the_old_run_pack_path_holds_no_second_copy(): void
    {
        // The run packs under rev/ were removed; the old path must not come back
        // as a second copy of the rules.
        $this->assertFileDoesNotExist($this->oldRunPackPath());
    }

    public function test_the_stale_branch_protection_claim_is_gone(): void
    {
        $doc = File::get($this->canonicalPath());

        $this->assertStringNotContainsString('Branch protection is ON', $doc);
        $this->assertStringContainsString('Upgrade to GitHub Pro', $doc);
    }
}
