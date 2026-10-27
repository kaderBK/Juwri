<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Jobs;

use App\Models\Theme;
use App\Models\ThemeVersion;
use Juwri\Modules\Storefront\Storefront\LighthouseGate;
use App\Storefront\ThemeSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * Theme Studio B0.9 (G9): run the P4-3 Lighthouse perf/a11y gate for a theme
 * version that passed lint, off the request.
 *
 * The gate is an ADDITIONAL signal, never a reason a submission breaks: when
 * the host has no `npx lighthouse`, no preview URL can be built, a preview is
 * not reachable anonymously (auditing an error page would be a meaningless
 * score — previews are temporary SIGNED URLs, see previewUrls()), or the run
 * itself errors,
 * gate_report records `skipped` with the reason and the version
 * stays in the human review queue. Only a real verdict below the bars
 * auto-rejects (ThemeSubmissionService::runGate).
 */
class RunThemeLighthouseGate implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 1;

    /** Two URLs × mobile + desktop, each run bounded by platform.lighthouse.timeout. */
    public int $timeout = 1200;

    /** Lifetime of the signed preview URLs (longer than the job's own timeout). */
    public const PREVIEW_TTL_MINUTES = 30;

    /**
     * @param  list<string>  $urls  override the audited URLs (default: the version's preview, LTR + RTL)
     */
    public function __construct(public int $versionId, public array $urls = [])
    {
        $this->onQueue('heavy'); // headless Chrome is heavy work
    }

    public function handle(ThemeSubmissionService $service, LighthouseGate $gate): void
    {
        $version = ThemeVersion::find($this->versionId);
        // Only a version still waiting for review is gated — a decision a human
        // already made is never overturned by a late audit.
        if (! $version || $version->status !== 'review') {
            return;
        }

        // `$gate::available()` (late static binding) so a test double can say no.
        if (! $gate::available()) {
            self::skip($version, 'Lighthouse is not installed on this host (npx lighthouse unavailable).');

            return;
        }

        $urls = $this->urls !== [] ? $this->urls : self::previewUrls($version);
        if ($urls === []) {
            self::skip($version, 'No preview URL could be built for this version.');

            return;
        }

        // Lighthouse runs without a session: the signed preview must answer an
        // anonymous GET, else the audit would score an error page. Skip then.
        foreach ($urls as $url) {
            $status = self::probe($url);
            if ($status < 200 || $status >= 300) {
                self::skip($version, "Preview is not publicly reachable (HTTP {$status}): {$url}");

                return;
            }
        }

        try {
            $service->runGate($version, $urls, $gate);
        } catch (\Throwable $e) {
            report($e);
            self::skip($version, 'Lighthouse run failed: '.mb_substr($e->getMessage(), 0, 300));
        }
    }

    /** A timed-out / killed job still leaves the reviewer a reason, not "pending" forever. */
    public function failed(?\Throwable $e = null): void
    {
        $version = ThemeVersion::find($this->versionId);
        if ($version && $version->status === 'review' && $version->gate_report === null) {
            self::skip($version, 'Lighthouse job did not finish'.($e ? ': '.mb_substr($e->getMessage(), 0, 300) : '.'));
        }
    }

    /**
     * The version's marketplace preview as temporary signed URLs, in the
     * default direction and in Arabic (RTL). The signature covers slug,
     * version and lang, so an anonymous auditor can open exactly these —
     * MarketplaceController@preview accepts a valid signature for a version
     * that is not published yet.
     *
     * @return list<string>
     */
    public static function previewUrls(ThemeVersion $version): array
    {
        $slug = Theme::whereKey($version->theme_id)->value('slug');
        if (! $slug) {
            return [];
        }
        $expires = now()->addMinutes(self::PREVIEW_TTL_MINUTES);
        $params = ['slug' => $slug, 'version' => (string) $version->semver];

        return [
            URL::temporarySignedRoute('marketplace.preview', $expires, $params),
            URL::temporarySignedRoute('marketplace.preview', $expires, $params + ['lang' => 'ar']),
        ];
    }

    /** HTTP status of an anonymous GET (0 when the request itself fails). */
    protected static function probe(string $url): int
    {
        try {
            return Http::timeout(20)->withoutRedirecting()->get($url)->status();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Record a skipped gate — the submission itself is untouched. */
    public static function skip(ThemeVersion $version, string $reason): void
    {
        $version->gate_report = [
            'skipped' => true,
            'passed' => null,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];
        $version->save();
    }
}
