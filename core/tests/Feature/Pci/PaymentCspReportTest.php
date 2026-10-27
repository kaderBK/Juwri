<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Pci;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\PaymentCspReport;
use Tests\TestCase;

/**
 * PCI DSS 4.0 req. 6.4.3: the payment-page CSP report endpoint stores what browsers report (both wire formats, no
 * IP address), ignores crawlers and unknown pages, keeps at most N rows per page and hour, and is rate limited.
 * Browser-posted: no CSRF token.
 */
class PaymentCspReportTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function report(string $page, mixed $payload, string $type = 'application/csp-report', string $ua = self::UA): TestResponse
    {
        return $this->call('POST', "/csp-report/payment/{$page}", [], [], [], [
            'CONTENT_TYPE' => $type, 'HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '203.0.113.9',
        ], (string) json_encode($payload));
    }

    private static function legacy(string $blocked = 'https://evil.example/skim.js'): array
    {
        return ['csp-report' => [
            'document-uri' => 'https://shop.example/payment/12', 'violated-directive' => 'script-src-elem', 'effective-directive' => 'script-src-elem',
            'blocked-uri' => $blocked, 'source-file' => 'https://shop.example/payment/12', 'line-number' => 41, 'disposition' => 'enforce',
            'script-sample' => '',
        ]];
    }

    public function test_a_report_uri_report_is_stored_without_the_ip_address(): void
    {
        $this->report('storefront.pay', self::legacy())->assertNoContent();

        $row = PaymentCspReport::query()->sole();
        $this->assertSame('storefront.pay', $row->page);
        $this->assertSame('script-src-elem', $row->directive);
        $this->assertSame('https://evil.example/skim.js', $row->blocked_uri);
        $this->assertSame('https://shop.example/payment/12', $row->document_uri);
        $this->assertSame(41, $row->line);
        $this->assertSame('enforce', $row->disposition);
        $this->assertSame(self::UA, $row->user_agent);
        $this->assertNotNull($row->created_at);
        $this->assertFalse(Schema::hasColumn('payment_csp_reports', 'ip'));
        $this->assertStringNotContainsString('203.0.113.9', (string) json_encode($row->getAttributes()));
    }

    public function test_reporting_api_batches_are_stored(): void
    {
        $this->report('gateway.paystack', [
            ['type' => 'csp-violation', 'url' => 'https://shop.example/pay', 'body' => ['documentURL' => 'https://shop.example/pay', 'effectiveDirective' => 'script-src-elem',
                'blockedURL' => 'https://evil.example/a.js', 'sourceFile' => 'https://shop.example/pay', 'lineNumber' => 3, 'disposition' => 'enforce', 'sample' => '']],
            ['type' => 'deprecation', 'body' => ['id' => 'x']],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'frame-src', 'blockedURL' => 'https://evil.example/f', 'disposition' => 'report']],
        ], 'application/reports+json')->assertNoContent();

        $this->assertSame(['https://evil.example/a.js', 'https://evil.example/f'], PaymentCspReport::query()->orderBy('id')->pluck('blocked_uri')->all());
        $this->assertSame(['enforce', 'report'], PaymentCspReport::query()->orderBy('id')->pluck('disposition')->all());
    }

    public function test_crawlers_unknown_pages_and_junk_store_nothing(): void
    {
        $this->report('storefront.pay', self::legacy(), ua: 'Mozilla/5.0 (compatible; Googlebot/2.1)')->assertNoContent();
        $this->report('storefront.pay', self::legacy(), ua: '')->assertNoContent();
        $this->report('no.such.page', self::legacy())->assertNoContent();
        $this->report('storefront.pay', 'not json')->assertNoContent();
        $this->report('Bad Page!', self::legacy())->assertNotFound();

        $this->assertSame(0, PaymentCspReport::query()->count());
    }

    public function test_long_values_are_cut_to_the_column_size(): void
    {
        $this->report('storefront.pay', self::legacy('https://evil.example/'.str_repeat('a', 900)))->assertNoContent();

        $this->assertSame(500, mb_strlen((string) PaymentCspReport::query()->sole()->blocked_uri));
    }

    public function test_at_most_the_configured_rows_per_page_and_hour_are_kept(): void
    {
        config(['payment_scripts.reports.max_per_page_per_hour' => 3]);

        for ($i = 0; $i < 5; $i++) {
            $this->report('storefront.pay', self::legacy("https://evil.example/{$i}.js"))->assertNoContent();
        }
        $this->report('gateway.razorpay', self::legacy())->assertNoContent();

        $this->assertSame(3, PaymentCspReport::query()->where('page', 'storefront.pay')->count());
        $this->assertSame(1, PaymentCspReport::query()->where('page', 'gateway.razorpay')->count(), 'the cap is per page');
    }

    public function test_the_endpoint_is_rate_limited_per_client(): void
    {
        $limit = (int) config('payment_scripts.reports.per_minute');
        for ($i = 0; $i < $limit; $i++) {
            $this->report('storefront.pay', self::legacy())->assertNoContent();
        }

        $this->report('storefront.pay', self::legacy())->assertStatus(429);
    }
}
