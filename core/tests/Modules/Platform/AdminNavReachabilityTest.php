<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WIRING-RUN B1-B5.
 *
 * Five superadmin pages shipped complete — controller, route, and a real Blade view —
 * but nothing anywhere linked to them, so they were reachable only by typing the URL.
 * This pins each one to an actual sidebar entry.
 *
 * The assertion reads the sidebar source rather than rendering it: admin views depend
 * on the '*' view composer that ViewServiceProvider registers only when APP_INSTALLED
 * is set, and it is deliberately unset in the test env.
 */
class AdminNavReachabilityTest extends TestCase
{
    /** Pages that must stay linked, mapped to the file expected to link them. */
    private const LINKED_PAGES = [
        'admin.support.index' => 'resources/views/admin/includes/sidebar.blade.php',
        'admin.withdrawals.index' => 'resources/views/admin/includes/sidebar.blade.php',
        'admin.fiscal.compliance' => 'resources/views/admin/includes/sidebar.blade.php',
        'admin.resellers.report' => 'resources/views/admin/includes/sidebar.blade.php',
        'admin.paylink.onboardings' => 'resources/views/admin/billing/gateways/index.blade.php',
        'admin.backup-health.index' => 'resources/views/admin/includes/sidebar.blade.php',
        'admin.menu-cache.index' => 'resources/views/admin/includes/sidebar.blade.php',
        'admin.analytics' => 'resources/views/admin/includes/sidebar.blade.php',
    ];

    public function test_every_previously_orphaned_admin_page_is_linked_from_the_ui(): void
    {
        $missing = [];

        foreach (self::LINKED_PAGES as $routeName => $relativePath) {
            if (! Route::has($routeName)) {
                if (\Juwri\Core\Editions\EditionPackages::all() === []) {
                    continue; // the page belongs to an edition package that is not installed (Community)
                }
                $missing[] = "route [{$routeName}] is not registered";

                continue;
            }

            $path = base_path($relativePath);
            if (! is_file($path)) {
                $missing[] = "expected linking view {$relativePath} is missing";

                continue;
            }

            if (! str_contains(file_get_contents($path), $routeName)) {
                $missing[] = "{$relativePath} no longer links [{$routeName}] — the page is orphaned again";
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }
}
