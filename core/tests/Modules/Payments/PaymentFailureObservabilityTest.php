<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Modules\Payments\Services\PaymentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * WI-M6 — payment-gateway failures must reach an operator. Before this, 27 call
 * sites did Log::info($e->getMessage()) — discarded below the production `warning`
 * floor — and 18 catch blocks were empty, so a rotated webhook secret, a revoked
 * BYOK key or a forged-webhook probe all presented as silence.
 */
class PaymentFailureObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private function withoutInstalled(): self
    {
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);

        return $this;
    }

    private function seedGateway(string $folder): void
    {
        PaymentGateway::create([
            'payment_folder' => $folder, 'payment_title' => ucfirst($folder), 'payment_install' => '1',
        ]);
    }

    private function stripeSignature(string $payload, string $secret, ?int $t = null): string
    {
        $t = $t ?? time();
        $sig = hash_hmac('sha256', $t.'.'.$payload, $secret);

        return "t={$t},v1={$sig}";
    }

    public function test_a_rejected_stripe_webhook_signature_raises_a_payment_platform_alert(): void
    {
        $this->seedGateway('stripe');
        config(['settings.stripe_webhook_secret' => 'whsec_test', 'settings.stripe_secret_key' => 'sk_test']);

        $payload = json_encode(['type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_1']]]);
        $bad = $this->stripeSignature($payload, 'whsec_test');
        $bad = substr($bad, 0, -1).($bad[-1] === '0' ? '1' : '0');

        $this->withoutInstalled()
            ->call('POST', route('webhook', 'stripe'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $bad,
            ], $payload)
            ->assertStatus(400);

        $this->assertDatabaseHas('platform_alerts', [
            'kind' => 'payment', 'message' => 'stripe: webhook rejected (invalid signature)',
        ]);
    }

    public function test_a_malformed_stripe_webhook_payload_raises_a_payment_platform_alert(): void
    {
        $this->seedGateway('stripe');
        config(['settings.stripe_webhook_secret' => 'whsec_test', 'settings.stripe_secret_key' => 'sk_test']);

        // Sign the non-JSON body with the REAL secret, so the signature passes and it is the
        // payload PARSE that fails — the "invalid payload" branch, not "invalid signature".
        $sig = $this->stripeSignature('not-json', 'whsec_test');

        $this->withoutInstalled()
            ->call('POST', route('webhook', 'stripe'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig,
            ], 'not-json')
            ->assertStatus(400);

        $this->assertDatabaseHas('platform_alerts', [
            'kind' => 'payment', 'message' => 'stripe: webhook rejected (invalid payload)',
        ]);
    }

    public function test_repeated_rejections_collapse_into_one_open_alert_row(): void
    {
        $this->seedGateway('stripe');
        config(['settings.stripe_webhook_secret' => 'whsec_test', 'settings.stripe_secret_key' => 'sk_test']);

        $payload = json_encode(['type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_1']]]);
        $bad = $this->stripeSignature($payload, 'whsec_test');
        $bad = substr($bad, 0, -1).($bad[-1] === '0' ? '1' : '0');

        for ($i = 0; $i < 3; $i++) {
            $this->withoutInstalled()->call('POST', route('webhook', 'stripe'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $bad,
            ], $payload)->assertStatus(400);
        }

        $this->assertSame(1, PlatformAlert::where('kind', 'payment')->whereNull('resolved_at')->count());
    }

    public function test_a_pay_failure_is_logged_at_error_and_reported(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake(); // report() is captured, not re-logged by the handler
        Log::spy();

        PaymentLog::failure('mollie', new \RuntimeException('boom'), ['transaction_id' => 7]);

        Log::shouldHaveReceived('error')->once();
        \Illuminate\Support\Facades\Exceptions::assertReported(\RuntimeException::class);
        // A declined checkout must NOT create a platform-alert row.
        $this->assertDatabaseCount('platform_alerts', 0);
    }

    public function test_no_bare_log_info_of_an_exception_message_survives_in_payment_methods(): void
    {
        $offenders = [];
        foreach (glob(app_path('Http/Controllers/User/PaymentMethods/*.php')) as $file) {
            if (str_contains(file_get_contents($file), 'Log::info($e->getMessage())')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'payment failures must not be swallowed by Log::info: '.implode(', ', $offenders));
    }

    public function test_no_one_line_empty_throwable_catch_survives_under_app(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains(file_get_contents($file->getPathname()), 'catch (\Throwable $e) {}')) {
                $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'empty catch blocks swallow errors: '.implode(', ', $offenders));
    }
}
