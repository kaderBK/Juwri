<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Content\Models\StudioCreditLedger;
use Juwri\Modules\Content\Models\StudioSession;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * WI-15-sec (review B-R2, P1): the Menu Studio Stripe top-up webhook fails
 * closed without a webhook secret (an empty secret let anyone sign an event),
 * credits only a paid session, and its route is throttled.
 */
class StudioStripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WHSEC = 'whsec_studio_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['settings.stripe_webhook_secret' => self::WHSEC]);
    }

    private function studioSession(): StudioSession
    {
        return StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
    }

    /** @return TestResponse<Response> */
    private function deliver(StudioSession $session, string $signWith, string $type = 'checkout.session.completed', string $paymentStatus = 'paid', string $checkoutId = 'cs_1', ?bool $livemode = null): TestResponse
    {
        $payload = (string) json_encode([
            'id' => 'evt_'.Str::random(10), 'object' => 'event', 'type' => $type, 'created' => time(), 'livemode' => $livemode,
            'data' => ['object' => [
                'id' => $checkoutId, 'object' => 'checkout.session', 'payment_status' => $paymentStatus,
                'metadata' => ['kind' => 'studio_topup', 'guest_token' => $session->guest_token, 'credits' => '50', 'bundle' => 'b50'],
            ]],
        ]);
        $ts = time();

        return $this->call('POST', '/studio/stripe-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$payload, $signWith),
        ], $payload);
    }

    private function credits(StudioSession $session): int
    {
        return (int) StudioCreditLedger::where('studio_session_id', $session->id)->sum('delta');
    }

    public function test_without_a_webhook_secret_nothing_is_accepted(): void
    {
        config(['settings.stripe_webhook_secret' => '']);
        $session = $this->studioSession();

        $this->deliver($session, '')->assertStatus(400);
        config(['settings.stripe_webhook_secret' => null]);
        $this->deliver($session, '')->assertStatus(400);

        $this->assertSame(0, $this->credits($session));
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $session = $this->studioSession();

        $this->deliver($session, 'whsec_someone_else')->assertStatus(400);

        $this->assertSame(0, $this->credits($session));
    }

    public function test_a_completed_but_unpaid_session_credits_nothing(): void
    {
        $session = $this->studioSession();

        $this->deliver($session, self::WHSEC, 'checkout.session.completed', 'unpaid')->assertOk();

        $this->assertSame(0, $this->credits($session));
    }

    public function test_a_paid_session_credits_once(): void
    {
        $session = $this->studioSession();

        $this->deliver($session, self::WHSEC)->assertOk();
        $this->deliver($session, self::WHSEC)->assertOk();

        $this->assertSame(50, $this->credits($session));
    }

    /** Lane D: a test-account event (livemode false) credits nothing while payment sandboxes are off. */
    public function test_a_test_mode_event_credits_nothing_while_sandboxes_are_off(): void
    {
        $session = $this->studioSession();
        config(['payments.sandbox.enabled' => false]);

        $this->deliver($session, self::WHSEC, 'checkout.session.completed', 'paid', 'cs_t', false)->assertOk();
        $this->assertSame(0, $this->credits($session));

        $this->deliver($session, self::WHSEC, 'checkout.session.completed', 'paid', 'cs_l', true)->assertOk();
        $this->assertSame(50, $this->credits($session));
    }

    public function test_a_delayed_payment_credits_when_it_succeeds(): void
    {
        $session = $this->studioSession();

        $this->deliver($session, self::WHSEC, 'checkout.session.completed', 'unpaid', 'cs_late')->assertOk();
        $this->deliver($session, self::WHSEC, 'checkout.session.async_payment_succeeded', 'paid', 'cs_late')->assertOk();

        $this->assertSame(50, $this->credits($session));
    }

    public function test_the_route_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('studio.stripe.webhook');

        $this->assertNotNull($route);
        $this->assertTrue(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:120,1,')), 'a prefixed throttle:120,1 throttle');
    }
}
