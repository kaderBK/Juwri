<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Pci;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\PaymentCspReport;
use Juwri\Core\Models\PaymentPageCheck;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Pci\PaymentPageMonitor;
use Tests\TestCase;

/**
 * PCI DSS 4.0 req. 11.6.1: the change-detection job fetches each payment page as a browser would (faked HTTP here),
 * hashes the script set (with the content of first-party / SRI-pinned scripts) and the security headers, and compares
 * them with the accepted baseline: no change → quiet; a new script, a changed script file or a changed header →
 * stored + alerted; pci:accept-baseline makes the current state the new baseline.
 */
class PaymentPageMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://shop.example/pay/gift-cards';

    private string $csp = "default-src 'self'; script-src 'self' 'nonce-AAA' https://js.paystack.co/v1/inline.js; object-src 'none'";

    private string $jquery = 'jquery-v1';

    private string $extraScript = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://shop.example', 'payment_scripts.monitor.probes' => false,
            'payment_scripts.monitor.targets' => [['page' => 'gateway.paystack', 'url' => self::PAGE]]]);
        $this->fakeSite();
    }

    private function fakeSite(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === self::PAGE) {
                $nonce = bin2hex(random_bytes(6)); // a new nonce on every response, like the real site

                return Http::response('<!DOCTYPE html><html><head><script src="https://shop.example/assets/global/js/jquery.min.js?v=1"></script>'
                    .'<script src="https://js.paystack.co/v1/inline.js"></script>'.$this->extraScript
                    .'<script nonce="'.$nonce.'">go()</script></head><body></body></html>', 200, [
                        'Content-Security-Policy' => str_replace('AAA', $nonce, $this->csp), 'X-Frame-Options' => 'SAMEORIGIN', 'Content-Type' => 'text/html']);
            }
            if (str_starts_with($request->url(), 'https://shop.example/assets/global/js/jquery.min.js')) {
                return Http::response($this->jquery, 200);
            }
            if ($request->url() === 'https://evil.example/skim.js') {
                return Http::response('steal()', 200);
            }

            return Http::response('', 404);
        });
    }

    private function checkOnce(): PaymentPageCheck
    {
        $this->artisan('pci:check-payment-pages');

        return PaymentPageCheck::query()->latest('id')->firstOrFail();
    }

    private function alerts(): int
    {
        return PlatformAlert::query()->where('kind', PaymentPageMonitor::ALERT_KIND)->count();
    }

    public function test_the_first_check_becomes_the_baseline_and_an_unchanged_page_is_quiet(): void
    {
        $this->artisan('pci:check-payment-pages')->assertSuccessful();
        $first = PaymentPageCheck::query()->sole();
        $this->assertSame(PaymentPageCheck::STATUS_BASELINE, $first->status);
        $this->assertTrue($first->baseline);
        $this->assertSame('gateway.paystack', $first->page);
        $this->assertSame(['/assets/global/js/jquery.min.js', 'https://js.paystack.co/v1/inline.js'], array_column((array) $first->scripts, 'src'));
        $this->assertSame('sha256:'.hash('sha256', 'jquery-v1'), $first->scripts[0]['content_hash'], 'first-party content is pinned');
        $this->assertNull($first->scripts[1]['content_hash'], 'an SRI-exempt vendor SDK is pinned by URL/CSP, not content');
        $this->assertStringContainsString("'nonce-*'", (string) $first->headers['content-security-policy']);

        $this->artisan('pci:check-payment-pages')->assertSuccessful();
        $second = PaymentPageCheck::query()->latest('id')->first();
        $this->assertSame(PaymentPageCheck::STATUS_UNCHANGED, $second->status);
        $this->assertFalse($second->baseline);
        $this->assertSame($first->script_hash, $second->script_hash);
        $this->assertSame($first->header_hash, $second->header_hash, 'the per-response nonce is not a change');
        $this->assertSame(0, $this->alerts());
    }

    public function test_a_new_script_is_detected_and_alerted(): void
    {
        $this->checkOnce();
        $this->extraScript = '<script src="https://evil.example/skim.js"></script>';

        $this->artisan('pci:check-payment-pages')->assertFailed();

        $check = PaymentPageCheck::query()->latest('id')->first();
        $this->assertSame(PaymentPageCheck::STATUS_CHANGED, $check->status);
        $this->assertContains('new script https://evil.example/skim.js', $check->changes);
        $this->assertContains('not authorised (6.4.3): unlisted script https://evil.example/skim.js on gateway.paystack', $check->changes);
        $this->assertSame(1, $this->alerts());
        $this->assertStringContainsString('new script https://evil.example/skim.js', (string) PlatformAlert::query()->value('message'));
    }

    public function test_a_modified_first_party_script_file_is_detected(): void
    {
        $this->checkOnce();
        $this->jquery = 'jquery-v1;steal()';

        $check = $this->checkOnce();

        $this->assertSame(PaymentPageCheck::STATUS_CHANGED, $check->status);
        $this->assertSame(['content changed: /assets/global/js/jquery.min.js'], $check->changes);
        $this->assertSame(1, $this->alerts());
    }

    public function test_a_security_header_change_is_detected(): void
    {
        $this->checkOnce();
        $this->csp = "default-src 'self'; script-src 'self' 'unsafe-inline' https:";

        $check = $this->checkOnce();

        $this->assertSame(PaymentPageCheck::STATUS_CHANGED, $check->status);
        $this->assertCount(1, $check->changes);
        $this->assertStringStartsWith('header content-security-policy changed', $check->changes[0]);
        $this->assertSame(1, $this->alerts());
    }

    public function test_accepting_the_baseline_makes_the_reviewed_state_known_good(): void
    {
        $this->checkOnce();
        $this->csp .= '; upgrade-insecure-requests';
        $this->assertSame(PaymentPageCheck::STATUS_CHANGED, $this->checkOnce()->status);

        $this->artisan('pci:accept-baseline', ['page' => ['gateway.paystack'], '--by' => 'sec-review-42'])->assertSuccessful();

        $accepted = PaymentPageCheck::query()->where('baseline', true)->sole();
        $this->assertSame('sec-review-42', $accepted->accepted_by);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame(PaymentPageCheck::STATUS_UNCHANGED, $this->checkOnce()->status);
    }

    public function test_accept_needs_a_page_or_all(): void
    {
        $this->artisan('pci:accept-baseline')->assertFailed();
        $this->artisan('pci:accept-baseline', ['page' => ['no.such.page']])->assertFailed();
        $this->artisan('pci:accept-baseline', ['--all' => true])->assertSuccessful();
        $this->assertSame(1, PaymentPageCheck::query()->where('baseline', true)->count());
    }

    public function test_an_unreachable_page_is_an_alerted_error_and_keeps_the_baseline(): void
    {
        $this->checkOnce();
        config(['payment_scripts.monitor.targets' => [['page' => 'gateway.paystack', 'url' => 'https://shop.example/gone']]]);

        $check = $this->checkOnce();

        $this->assertSame(PaymentPageCheck::STATUS_ERROR, $check->status);
        $this->assertSame('HTTP 404', $check->error);
        $this->assertSame(1, PaymentPageCheck::query()->where('baseline', true)->count());
        $this->assertSame(1, $this->alerts());
    }

    public function test_browser_reported_violations_since_the_last_check_are_alerted_and_old_reports_pruned(): void
    {
        $this->checkOnce();
        PaymentCspReport::query()->create(['page' => 'storefront.pay', 'blocked_uri' => 'https://evil.example/skim.js', 'disposition' => 'enforce', 'created_at' => now()->addSecond()]);
        PaymentCspReport::query()->create(['page' => 'storefront.pay', 'blocked_uri' => 'https://old.example/x.js', 'created_at' => now()->subDays(200)]);
        $this->travel(5)->seconds();

        $this->checkOnce();

        $this->assertStringContainsString('storefront.pay: https://evil.example/skim.js ×1', (string) PlatformAlert::query()->where('kind', PaymentPageMonitor::ALERT_KIND)->value('message'));
        $this->assertSame(1, PaymentCspReport::query()->count(), 'reports past the retention are deleted');
    }

    public function test_the_job_is_scheduled_weekly_by_default(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'pci:check-payment-pages'));

        $this->assertCount(1, $events);
        $this->assertSame('30 4 * * 1', $events->first()->expression);
    }
}
