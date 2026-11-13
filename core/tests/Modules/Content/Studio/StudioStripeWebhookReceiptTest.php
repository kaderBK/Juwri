<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Modules\Content\Models\StudioCreditLedger;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Tests\TestCase;

/**
 * J25-7 part 4 (bug fix): the Studio top-up webhook credits once per verified paid checkout session. Before,
 * StudioCreditService::credit's ref check was check-then-insert — two concurrent copies could both credit.
 */
class StudioStripeWebhookReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const WHSEC = 'whsec_studio_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['settings.stripe_webhook_secret' => self::WHSEC]);
    }

    private function deliver(StudioSession $session, string $type = 'checkout.session.completed')
    {
        $payload = (string) json_encode([
            'id' => 'evt_'.Str::random(10), 'object' => 'event', 'type' => $type, 'created' => time(),
            'data' => ['object' => [
                'id' => 'cs_r1', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'metadata' => ['kind' => 'studio_topup', 'guest_token' => $session->guest_token, 'credits' => '50', 'bundle' => 'b50'],
            ]],
        ]);
        $ts = time();

        return $this->call('POST', '/studio/stripe-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$payload, self::WHSEC),
        ], $payload);
    }

    private function credits(StudioSession $session): int
    {
        return (int) StudioCreditLedger::where('studio_session_id', $session->id)->sum('delta');
    }

    public function test_a_concurrent_copy_is_a_no_op_and_the_session_is_credited_once(): void
    {
        $session = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);

        // Another delivery for this paid session holds the claim and is crediting right now.
        WebhookReceipts::claim('studio_stripe', 'paid:cs_r1');
        $this->deliver($session)->assertStatus(503); // P6-139: in flight → Stripe is told to retry, never a 2xx
        $this->assertSame(0, $this->credits($session));

        // That delivery died; Stripe's retry (even as the other event type) takes it over and credits once.
        DB::table('webhook_receipts')->update(['claimed_at' => now()->subMinutes(WebhookReceipts::STALE_AFTER_MINUTES + 1)]);
        $this->deliver($session, 'checkout.session.async_payment_succeeded')->assertOk();
        $this->deliver($session)->assertOk();
        $this->assertSame(50, $this->credits($session));
    }
}
