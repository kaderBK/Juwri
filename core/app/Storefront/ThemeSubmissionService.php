<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Storefront;

use App\Jobs\RunThemeLighthouseGate;
use App\Models\ThemeVersion;
use Juwri\Modules\Storefront\Storefront\LighthouseGate;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Juwri\Modules\Storefront\Storefront\ThemePackageInstaller;

/**
 * P4-4: the submission front of the review pipeline.
 *
 *   zip → hard security gates (P2-5 validator, throws) → immutable install
 *   (status=review) → linter (P4-2) persisted to lint_report →
 *   lint errors = AUTO-REJECT → else waits in the human review queue.
 *
 * The Lighthouse gate (P4-3) runs when a preview URL exists for the version
 * (P4-5 seed store); its verdict lands in gate_report the same way. Approve/
 * reject/publish are admin actions (ThemeReviewController).
 *
 * Theme Studio B0.9: a submission that passes lint queues the gate
 * (RunThemeLighthouseGate) against its marketplace preview. A host without
 * Lighthouse records the gate as skipped — it never fails the submission.
 * `platform.lighthouse.auto_gate` switches the auto-run off (default: on,
 * except under unit tests, which dispatch it explicitly).
 */
class ThemeSubmissionService
{
    public function __construct(
        protected ThemePackageInstaller $installer,
        protected ThemeLinter $linter,
    ) {}

    /**
     * @return ThemeVersion the submitted version (status review|rejected)
     *
     * @throws \RuntimeException when the package fails the hard security gates.
     */
    public function submit(string $zipPath, ?int $sellerId = null): ThemeVersion
    {
        $theme = $this->installer->install($zipPath, 'review', $sellerId);

        $version = ThemeVersion::whereHas('theme', fn ($q) => $q->where('slug', $theme->slug))
            ->where('semver', $theme->version)
            ->firstOrFail();

        $report = $this->linter->lint($theme);
        $version->lint_report = $report;

        if (! $report['passed']) {
            // Automated gate failure — no human time spent; author gets the report.
            $version->status = 'rejected';
            $version->review_notes = 'Automated lint failed: '
                .collect($report['errors'])->pluck('rule')->unique()->implode(', ');
        }

        $version->save();

        if ($report['passed'] && (bool) config('platform.lighthouse.auto_gate', ! app()->runningUnitTests())) {
            dispatch_job_reliably(new RunThemeLighthouseGate($version->id));
        }

        return $version;
    }

    /**
     * P4-3 hook: run the perf/a11y gate against the version's preview URLs
     * (available once P4-5 provides the seed store). Auto-rejects below bar.
     *
     * @param  list<string>  $urls
     */
    public function runGate(ThemeVersion $version, array $urls, ?LighthouseGate $gate = null): ThemeVersion
    {
        $verdict = ($gate ?? new LighthouseGate)->evaluate($urls);
        $version->gate_report = $verdict;

        if (! $verdict['passed'] && $version->status === 'review') {
            $version->status = 'rejected';
            $version->review_notes = 'Quality gate failed: '.implode('; ', $verdict['reasons']);
        }

        $version->save();

        return $version;
    }
}
