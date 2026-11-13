<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The /csp-report sink records the per-host abuse METRIC on every browser report
 * (the signal AbuseMonitor's digest reads), but rate-limits the log WARNING to at
 * most one line per host per hour. Report-only CSP with `connect-src 'self'` trips
 * on every 3rd-party widget, so an un-throttled log write was ~57k synchronous
 * lines/day on the CPU-watched prod box.
 */
class CspReportRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Cache::flush();
    }

    private function report(string $host, string $agent = self::BROWSER_UA): void
    {
        $this->withHeader('User-Agent', $agent)
            ->postJson('/csp-report', ['csp-report' => ['blocked-uri' => "https://{$host}/beacon"]])
            ->assertNoContent();
    }

    public function test_log_is_rate_limited_per_host_while_the_metric_records_every_report(): void
    {
        $this->report('analytics.example.com');
        // First report set the per-host log guard → the WARNING was written once.
        $this->assertSame(1, Cache::get('csp-log:analytics.example.com'));
        // Guard present → a second same-host report cannot write another log line.
        $this->assertFalse(Cache::add('csp-log:analytics.example.com', 1, 3600));

        $this->report('analytics.example.com'); // same host → log suppressed
        $this->report('chat.example.com');       // new host → logs again (own guard)
        $this->assertSame(1, Cache::get('csp-log:chat.example.com'));

        // The METRIC still counts EVERY report (per host/day upsert): 2 + 1.
        $this->assertSame(2, (int) DB::table('platform_metrics')
            ->where('metric', 'csp.violation')->where('label', 'analytics.example.com')->value('count'));
        $this->assertSame(1, (int) DB::table('platform_metrics')
            ->where('metric', 'csp.violation')->where('label', 'chat.example.com')->value('count'));
    }

    /**
     * A crawler has no CSP to violate, so every report it sends is both noise and
     * a full framework boot. In one 6h prod window 18,253 of the 19,967 POSTs to
     * this endpoint came from meta-externalads alone (92%). Drop bots before any
     * work: no log guard, no metric row, still a well-formed empty 204.
     */
    public function test_crawler_reports_are_dropped_before_any_work(): void
    {
        // P6-147: meta-externalads is on the global BlockBadBots list, so it never reaches the controller (403, even cheaper).
        $this->withHeader('User-Agent', 'meta-externalads/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)')
            ->postJson('/csp-report', ['csp-report' => ['blocked-uri' => 'https://tracker.example.com/beacon']])
            ->assertForbidden();

        foreach ([
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'facebookexternalhit/1.1',
            'HeadlessChrome/120.0.0.0',
            '',
        ] as $agent) {
            $this->report('tracker.example.com', $agent);
        }

        $this->assertNull(Cache::get('csp-log:tracker.example.com'), 'a bot report must not take the log guard');
        $this->assertSame(0, (int) DB::table('platform_metrics')
            ->where('metric', 'csp.violation')->where('label', 'tracker.example.com')->count(),
            'a bot report must not reach the abuse metric');

        // The same host from a real browser is still recorded — the drop is by
        // agent, not by host, so a genuine violation is never lost.
        $this->report('tracker.example.com');
        $this->assertSame(1, (int) DB::table('platform_metrics')
            ->where('metric', 'csp.violation')->where('label', 'tracker.example.com')->value('count'));
    }

    /**
     * `throttle:60,1` shares one counter per visitor across every route that uses it, so a page that sends
     * ~60 CSP reports on load used to leave the same visitor's geo/autocomplete (and coupon, etc.) at 429.
     */
    public function test_a_csp_report_burst_does_not_spend_the_visitors_other_throttles(): void
    {
        for ($i = 0; $i < 70; $i++) {
            $this->report('widget.example.com');
        }

        $this->withHeader('User-Agent', self::BROWSER_UA)
            ->getJson('/geo/autocomplete?q=a')
            ->assertOk();
    }
}
