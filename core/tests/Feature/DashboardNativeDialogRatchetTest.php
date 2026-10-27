<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * Ratchet: the owner dashboard must never call native alert()/confirm()/prompt().
 *
 * Native dialogs are unreliable — after a couple of them the browser offers
 * "Prevent this page from creating additional dialogs", and once ticked every
 * later confirm()/prompt() returns false/null, so the gated action silently
 * never runs (an owner clicks Delete and nothing happens). This bit the retail/
 * service editors, the biolink copy form and the floor-map tile editor.
 *
 * Use the shared helpers in partials/toast.blade.php instead: a form/link opts
 * into confirmation with data-confirm="…", and code that needs input calls
 * window.dashPrompt(opts) => Promise<string|null>. Both are pure-DOM modals that
 * survive Turbo and are CSP-clean. dashConfirm/dashPrompt are exempt by name.
 */
class DashboardNativeDialogRatchetTest extends TestCase
{
    public function test_owner_dashboard_blades_use_no_native_dialogs(): void
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
            // Strip comments so explanatory text ("replaces native prompt()") never
            // trips the scan: blade comments, then block, then line comments.
            $body = preg_replace('/\{\{--.*?--\}\}/s', '', $body);
            $body = preg_replace('#/\*.*?\*/#s', '', $body);
            $body = preg_replace('#//[^\n]*#', '', $body);

            // Native calls are lowercase (confirm/prompt/alert); dashConfirm and
            // dashPrompt keep their capital letter, so a lowercase, not-preceded-
            // by-a-letter match skips them.
            // The partner panel's kit dialog (window.RESELLER_PANEL.confirm) is a method, not native.
            $body = str_replace('RESELLER_PANEL.confirm(', 'RESELLER_PANEL.kitConfirm(', $body);
            if (preg_match_all('/(?<![A-Za-z])(confirm|prompt|alert)\s*\(/', $body, $matches)) {
                $rel = ViewRoots::relative($file->getPathname());
                $offenders[] = $rel.' ('.implode(', ', $matches[1]).')';
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'native alert()/confirm()/prompt() are unreliable on the dashboard — use '
            ."data-confirm=\"…\" or window.dashPrompt() (see partials/toast.blade.php):\n"
            .implode("\n", $offenders)
        );
    }
}
