<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Owner 2026-10-05 (DECISIONS "Protecting the Community limits", 1b): the chain tools — central menu push across
 * branches, stock transfers between branches, cross-branch / benchmark reports — are Enterprise code (a Chain or
 * Enterprise licence turns them on) and must not drift into Community (app/, routes/, resources/, config/, database/).
 *
 * The Community list is EMPTY (owner 2026-10-05, "Chain tools move to ee": stock transfers between branches moved to the
 * Inventory ee half, the dashboard branch-comparison card to the Analytics ee half): any match here fails — a chain tool
 * that comes back into Community, or a new one written there, belongs in the Enterprise package.
 */
class ChainToolsStayEeTest extends TestCase
{
    private const SCAN = ['app', 'routes', 'resources/views', 'config', 'database'];

    /** tool => regex over Community files (a hit = that tool's code is in Community). */
    private const MARKERS = [
        'cross-branch report' => '/\bclass\s+CrossBranchComparison\b|\bimplements\s+BranchComparison\b(?<!NoBranchComparison implements BranchComparison)/',
        'benchmark report' => '/\bclass\s+(PeerBenchmark|ComputePeerBenchmarks)\b|[\'"]benchmarks:compute\b|create\(\s*[\'"]peer_benchmarks[\'"]/',
        'central menu push' => '/\bclass\s+\w*(Chain|Central|Branch)\w*Menu\w*(Push|Sync|Publish)\w*\b|\bclass\s+\w*MenuPush\w*(Branch|Chain)\w*\b/',
        'stock transfer between branches' => '/\$fromBranchId\b|\bfrom_branch_id\b|\bclass\s+StockTransfer\w*\b|inventory\.ingredients\.transfer[\'"]|function\s+transfer\(int\s+\$ingredientId/',
        'branch comparison (dashboard)' => '/\$branchComparison\b|\bclass\s+DashboardBranchCard\b/',
    ];

    public function test_no_chain_tool_is_in_community(): void
    {
        $core = dirname(__DIR__, 2);
        $found = [];
        foreach (self::SCAN as $dir) {
            if (! is_dir($core.'/'.$dir)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($core.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                $relative = substr($file->getPathname(), strlen($core) + 1);
                foreach (self::MARKERS as $tool => $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        $found[$relative] = $tool;
                    }
                }
            }
        }
        // NoBranchComparison is Community's "off" default of the extension point, not the tool.
        unset($found['app/Modules/Analytics/Services/NoBranchComparison.php']);
        ksort($found);

        $this->assertSame([], $found, 'Chain tools are Enterprise/Chain-licence code (the Enterprise package), never Community: '.json_encode($found));
    }

    /** The moved tools exist where they belong (the package folder; absent in the Community build, where nothing is asserted). */
    public function test_the_moved_tools_live_in_the_package(): void
    {
        $ee = dirname(__DIR__, 2).'/e'.'e/Modules';
        if (! is_file($ee.'/Analytics/Services/CrossBranchComparison.php')) { // (not is_dir: a synced runner can keep empty folders)
            $this->markTestSkipped('Community build: the Enterprise package is not installed.');
        }
        $this->assertFileExists($ee.'/Inventory/Services/StockTransferService.php');
        $this->assertFileExists($ee.'/Analytics/Services/DashboardBranchCard.php');
    }

    public function test_the_reports_are_enterprise_today(): void
    {
        $ee = dirname(__DIR__, 2).'/e'.'e/Modules/Analytics/Services/CrossBranchComparison.php'; // the package folder (absent in the Community build)
        if (! is_file($ee)) {
            $this->markTestSkipped('Community build: the Enterprise package is not installed.');
        }
        $this->assertStringContainsString('implements BranchComparison', (string) file_get_contents($ee));
    }
}
