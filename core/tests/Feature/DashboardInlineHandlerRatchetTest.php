<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Juwri\Core\Support\Csp\DashboardPolicy;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * Ratchet for the dashboard CSP enforce path. DashboardPolicy sets
 * script-src-attr 'none' ({@see DashboardPolicy}), so an inline
 * event-handler attribute (onclick=, onchange=, onerror=…) on any owner blade
 * would be a dead control the moment CSP_ENFORCE_DASHBOARD is flipped on.
 *
 * Every such handler was converted to a delegated hook: data-ui-* on the owner
 * screens (assets/owner-ui/actions.js, WI-16c) and data-lm-* on the owner views
 * not moved yet (assets/owner-ui/services.js). This test keeps it at zero:
 * add a new inline handler and it fails here — use a data-ui-* hook (or @click /
 * x-on: for Alpine, which are NOT DOM handler attributes) instead.
 *
 * Alpine bindings (@click, x-on:click, :onx) are compiled by Alpine, never emitted
 * as on* attributes, so they do not match and are correctly ignored.
 */
class DashboardInlineHandlerRatchetTest extends TestCase
{
    public function test_owner_dashboard_blades_have_no_inline_event_handlers(): void
    {
        $offenders = [];

        // The bought theme (J9) folder left with WI-16h; its screens live in these kit folders — in resources/views and
        // in the ee modules' Views folders (P1-13).
        $files = [];
        foreach (['owner', 'marketing', 'auth', 'site', 'reseller', 'giftcards'] as $dir) {
            foreach (ViewRoots::sub($dir) as $path) {
                $files = array_merge($files, File::allFiles($path));
            }
        }

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $body = File::get($file->getPathname());

            if (preg_match_all(
                '/\son(?:click|change|error|load|focus|blur|input|submit|search|keyup|keydown|mouseenter|mouseleave)\s*=/i',
                $body,
                $matches
            )) {
                $rel = ViewRoots::relative($file->getPathname());
                $offenders[] = $rel.' ('.count($matches[0]).')';
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'inline event handlers would break under CSP_ENFORCE_DASHBOARD — convert to data-ui-* '
            ."(see assets/owner-ui/actions.js) or Alpine @click:\n".implode("\n", $offenders)
        );
    }
}
