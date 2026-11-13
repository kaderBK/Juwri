<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Providers;

use App\Services\Ai\E2eStubAiService;
use Illuminate\Support\ServiceProvider;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiProviderChain;

/**
 * E2E-only deterministic stub bindings (rev/platform-e2e-v2 V0.2).
 *
 * Registered in config/app.php, but every binding is fenced behind a HARD guard:
 * it does NOTHING unless the app is local/testing AND env('E2E_STUBS') is truthy.
 * In production (APP_ENV=production) register() returns immediately, so no fake
 * gateway/AI is ever bound and the stub classes stay inert. No prod business
 * logic is touched — this only swaps container bindings for the test env.
 *
 * Guards armed by the v2 runner (rev/platform-e2e/bin/e2e.sh exports E2E_STUBS=1
 * for v2 runs) and by seeders/tests that set E2E_STUBS explicitly.
 *
 * Bindings when armed:
 *   - AiService                 → E2eStubAiService (deterministic text/JSON; C7)
 *   - ResellerGatewayDriver     → FakeResellerDriver (success, or failure when
 *                                 E2E_STUB_GATEWAY_FAIL is truthy; C6)
 *
 * The storefront customer gateway (A4) needs NO container binding — it is the
 * seeded `stub` payment method dispatched to PaymentMethods\StubController, which
 * carries its own identical guard.
 *
 * The SMS/OTP "stub" (C8) is pure config (SMS_PROVIDER=log → sms_log), so nothing
 * is bound here.
 */
class E2eStubServiceProvider extends ServiceProvider
{
    /** Only local/testing with E2E_STUBS armed enables any stub. */
    public static function armed(): bool
    {
        return app()->environment(['local', 'testing'])
            && filter_var(config('app.e2e_stubs'), FILTER_VALIDATE_BOOLEAN);
    }

    public function register(): void
    {
        if (! self::armed()) {
            return;
        }

        // Deterministic AI — no real provider call, no key spend.
        $this->app->singleton(Ai::class, function ($app) {
            return new E2eStubAiService($app->make(AiProviderChain::class));
        });
        // Reseller BYOK billing (scripted success/failure): bound by the Reseller module's provider (ee, P1-13 13f).
    }
}
