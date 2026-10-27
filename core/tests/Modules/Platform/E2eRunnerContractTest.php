<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * W0.4 — the browser-e2e runner's registration contract.
 *
 * The default `npm run test:e2e` journey list must stay byte-for-byte frozen
 * (per-PR nightly baseline), and the opt-in `E2E_RUN_PLATFORM=1` group must
 * auto-register every `journeys/platform/*.js`. Both are read from the runner's
 * own `--list` output so the test cannot drift from `run.js`.
 *
 * Grouped `platform-e2e` so both the per-push sqlite unit lane (ci.yml,
 * `--exclude-group=platform-e2e`) and the nightly qrtest_-prefix lane
 * (nightly.yml, same exclude) skip it: `run.js` top-level `require`s the e2e
 * node deps (puppeteer/lib), which are not installed in either checkout, so
 * `--list` yields nothing there. It runs on the integrator/local, where the
 * e2e `node_modules` exist.
 *
 * This is the ONLY class still carrying this tag — PR #34 revived the other 10
 * platform-e2e classes (PlatformE2eB1-B12, Platform\B5/B6, …) off it, and they
 * gate both lanes for real on sqlite `:memory:`. `platform-e2e` was kept here
 * rather than renamed to a dedicated tag (e.g. `e2e-runner`): the tag now only
 * ever matches this one env-bound test, so there is nothing left to accidentally
 * un-exclude, and a rename would just be three files (this one + both
 * workflows) that could silently drift out of sync for no behavioural gain.
 */
#[Group('platform-e2e')]
class E2eRunnerContractTest extends TestCase
{
    /** The frozen default journey list (no env flags). */
    private const DEFAULT_JOURNEYS = [
        'storefront-checkout',
        'dashboard-login',
        'staff-pwa-login',
        'role-pages-smoke',
    ];

    private function node(): ?string
    {
        foreach (['/usr/local/bin/node', '/opt/homebrew/bin/node'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        $which = trim((string) @shell_exec('command -v node 2>/dev/null'));

        return $which !== '' ? $which : null;
    }

    /** @return string[] the runner's registered journeys for the given env */
    private function runList(array $env = []): array
    {
        $node = $this->node();
        if ($node === null) {
            $this->markTestSkipped('node is not available on this runner');
        }

        $run = base_path('tests/e2e/run.js');
        $this->assertFileExists($run);

        // Hermetic: the self-hosted CI runner's ambient env can carry E2E_RUN_*
        // toggles (e.g. E2E_RUN_PLATFORM), which would leak into run.js's --list
        // and break the frozen default. Unset every group toggle first, then apply
        // only the env this call explicitly asks for.
        $toggles = [
            'E2E_RUN_PLATFORM', 'E2E_RUN_PLATFORM_V2', 'E2E_RUN_RETAINED_CSP',
            'E2E_RUN_POS', 'E2E_RUN_POS_REFUND', 'E2E_RUN_RETENTION', 'E2E_RUN_MULTITAB',
            'E2E_RUN_LANG', 'E2E_RUN_A11Y', 'E2E_RUN_REVIEWS', 'E2E_RUN_ORDER_TYPE',
            'E2E_RUN_OFFERS', 'E2E_RUN_TPL_MATRIX', 'E2E_RUN_DOUBLE_SUBMIT',
            'E2E_RUN_FUSION_TENANCY', 'E2E_RUN_FUSION_CONSOLE', 'E2E_RUN_MULTICART',
            'E2E_RUN_DOCS', 'E2E_RUN_BASELINE', 'E2E_RUN_SHELL', 'E2E_RUN_P112_B', 'E2E_RUN_P310', 'E2E_RUN_UNCOVERED', 'E2E_JOURNEYS',
        ];
        $prefix = 'env ';
        foreach ($toggles as $t) {
            $prefix .= '-u '.escapeshellarg($t).' ';
        }
        foreach ($env as $k => $v) {
            $prefix .= escapeshellarg($k.'='.$v).' ';
        }
        $cmd = $prefix.escapeshellarg($node).' '.escapeshellarg($run).' --list 2>/dev/null';
        $out = (string) shell_exec($cmd);

        return array_values(array_filter(array_map('trim', explode("\n", $out)), fn ($l) => $l !== ''));
    }

    public function test_default_journey_list_is_frozen(): void
    {
        $this->assertSame(self::DEFAULT_JOURNEYS, $this->runList());
    }

    public function test_platform_group_registers_every_platform_journey(): void
    {
        $list = $this->runList(['E2E_RUN_PLATFORM' => '1']);

        // The default set is still present, in order, ahead of the group.
        $this->assertSame(self::DEFAULT_JOURNEYS, array_slice($list, 0, count(self::DEFAULT_JOURNEYS)));

        $dir = base_path('tests/e2e/journeys/platform');
        $files = glob($dir.'/*.js') ?: [];
        $this->assertNotEmpty($files, 'no platform journeys found to register');
        foreach ($files as $f) {
            $slug = basename($f, '.js');
            $this->assertContains($slug, $list, "platform journey '{$slug}' is not registered under E2E_RUN_PLATFORM=1");
        }
    }
}
