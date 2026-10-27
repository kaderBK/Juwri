<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Tests\TestCase;

/**
 * Studio Stripe top-up: checkout guards + webhook crediting (idempotent).
 * The live Stripe Session::create is not exercised (network); the security-
 * critical guards + the metadata->credit mapping are.
 */
class StudioCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_checkout_rejects_unknown_bundle(): void
    {
        $this->postJson('/studio/checkout', ['bundle' => 'not-a-tier'])->assertStatus(422);
    }

    public function test_checkout_503_without_stripe_key(): void
    {
        config(['settings.stripe_secret_key' => null]);
        // A valid bundle key, but no Stripe key configured -> 503 (not a 500).
        $this->postJson('/studio/checkout', ['bundle' => 'creator'])->assertStatus(503);
    }

    /** Lane D: the Stripe unit_amount is in the currency's minor unit (before: always ×100). */
    public function test_stripe_unit_amount_uses_the_currency_minor_unit(): void
    {
        $this->assertSame(999, StudioController::stripeUnitAmount(9.99, 'usd'));
        $this->assertSame(500, StudioController::stripeUnitAmount(500, 'jpy'));
        $this->assertSame(1230, StudioController::stripeUnitAmount('1.230', 'kwd'));
        $this->assertNull(StudioController::stripeUnitAmount('1.235', 'kwd'), 'finer than Stripe can charge: refused, not rounded');
        $this->assertNull(StudioController::stripeUnitAmount(5000, 'ugx'), 'a currency Stripe is refused for');
    }

    public function test_checkout_503_with_a_test_key_while_sandboxes_are_off(): void
    {
        config(['settings.stripe_secret_key' => 'sk_test_abc', 'payments.sandbox.enabled' => false]);
        $this->postJson('/studio/checkout', ['bundle' => 'creator'])->assertStatus(503);
    }

    public function test_webhook_metadata_credits_session_once(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $ctrl = new StudioController;
        $m = new \ReflectionClass($ctrl)->getMethod('creditTopup');
        $m->setAccessible(true);

        $meta = ['kind' => 'studio_topup', 'guest_token' => $s->guest_token, 'credits' => '120'];
        $m->invoke($ctrl, $meta, 'cs_test_ABC');
        $m->invoke($ctrl, $meta, 'cs_test_ABC'); // replayed webhook -> idempotent

        $this->assertSame(120, app(StudioCreditService::class)->balance($s));
    }

    public function test_webhook_ignores_non_topup_metadata(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $ctrl = new StudioController;
        $m = new \ReflectionClass($ctrl)->getMethod('creditTopup');
        $m->setAccessible(true);

        $m->invoke($ctrl, ['kind' => 'something_else', 'guest_token' => $s->guest_token, 'credits' => '999'], 'cs_x');

        $this->assertSame(0, app(StudioCreditService::class)->balance($s));
    }
}
