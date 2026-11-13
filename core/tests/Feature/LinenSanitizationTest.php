<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * P0-2b: Linen brand/owner views must not echo DB-authored content raw, and the
 * generic UI components (table, tabs) must escape by default (raw only for an
 * explicit HtmlString opt-in).
 */
class LinenSanitizationTest extends TestCase
{
    public function test_no_raw_db_content_sinks_in_linen(): void
    {
        $banned = [
            '{!! $faq->faq_content',
            '{!! $faq->answer',
            '{!! $blog->details',
            '{!! $settings->company_bank_info',
            '{!! @$settings->company_bank_info',
            '{!! $store->description',
        ];
        $offenders = [];
        foreach (['owner', 'marketing', 'auth', 'site', 'reseller'] as $sub) {
            foreach (ViewRoots::sub($sub) as $dir) { // resources/views + the ee modules' Views (P1-13)
                $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($rii as $f) {
                    if (! str_ends_with($f->getFilename(), '.blade.php')) {
                        continue;
                    }
                    $c = file_get_contents($f->getPathname());
                    foreach ($banned as $needle) {
                        if (str_contains($c, $needle)) {
                            $offenders[] = ViewRoots::relative($f->getPathname()).' :: '.$needle;
                        }
                    }
                }
            }
        }
        $this->assertSame([], $offenders, "Raw DB-content sinks:\n".implode("\n", $offenders));
    }

    public function test_kit_table_component_escapes_by_default(): void
    {
        // The owner ui/table + ui/tabs components left with WI-16h; the kit data
        // table is the generic one now and escapes cell values (no raw echo).
        $src = file_get_contents(resource_path('views/components/ui/data-table.blade.php'));
        $this->assertStringNotContainsString('{!!', $src);
    }

    public function test_no_editor_backup_views_shipped(): void
    {
        $baks = [];
        foreach (['owner', 'marketing', 'auth', 'site', 'reseller'] as $sub) {
            foreach (ViewRoots::sub($sub) as $dir) {
                $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($rii as $f) {
                    if (str_contains($f->getFilename(), '.bak')) {
                        $baks[] = $f->getFilename();
                    }
                }
            }
        }
        $this->assertSame([], $baks, 'Editor backup blade files must not ship.');
    }
}
