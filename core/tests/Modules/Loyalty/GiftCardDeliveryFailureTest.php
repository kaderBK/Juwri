<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\GiftCard;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Mail\GiftCardMail;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-214: gift-card e-mail failures were silent and retried every 5 min forever,
 * stranding the recipient with no notice and the owner with no signal. These tests
 * pin the new contract: a failing transport applies 5/10/20/40/60-minute backoff,
 * stops after GIFT_CARD_MAX_DELIVERY_ATTEMPTS, surfaces an owner notice on the
 * row, and never touches the card's stored-value balance.
 */
class GiftCardDeliveryFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        // Owner layout reads $settings — share them so a GET renders. Mirrors the
        // GiftCardAdminTest / PlatformE2eTestCase pattern.
        $this->shareLayoutGlobals();
        // Bypass the platform middleware stack (Installed, PreventRequestForgery,
        // feature:gift_cards, dashboard.sync, owner.screen, …) so the test reaches
        // the GiftCardAdminController regardless of plan / feature-switch state —
        // mirrors GiftCardAdminTest::setUp. Mail::shouldReceive('to')->andThrow()
        // and Mail::fake() still work because the Mail facade is not a route
        // middleware.
        $this->withoutMiddleware();
        // No Mail::fake() — a failing-transport test mocks Mail::to() to throw, and a
        // successful-transport test starts its own Mail::fake() right before acting.
    }

    private function ownerAndRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');

        return [$owner, $r];
    }

    private function activeCard(int $storeId, string $email = 'gift@x.com'): GiftCard
    {
        $card = GiftCardService::issueManual($storeId, 50.0, ['recipient_email' => $email]);

        return $card->fresh();
    }

    /**
     * Mail::to() throws a RuntimeException, so the GiftCardMail send raises inside
     * GiftCardService::deliver(). Mirrors the ee-suite pattern
     * (ResellerOverdueSuspensionTest) — Mail::swap($backup) in tearDown restores the
     * real mailer so a follow-up test (or follow-up step) can deliver cleanly.
     */
    private function armFailingTransport(): void
    {
        $this->failingMailerBackup = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
    }

    private $failingMailerBackup = null;

    protected function tearDown(): void
    {
        if ($this->failingMailerBackup !== null) {
            Mail::swap($this->failingMailerBackup);
            $this->failingMailerBackup = null;
        }
        parent::tearDown();
    }

    public function test_first_failure_records_attempt_and_backoff(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);
        $this->armFailingTransport();
        Log::spy();

        $before = Carbon::now();
        $delivered = GiftCardService::deliver($card->fresh());
        $after = Carbon::now();

        $this->assertFalse($delivered, 'a failing transport does not deliver');
        $fresh = $card->fresh();
        $this->assertNull($fresh->delivered_at, 'no delivered_at on failure');
        $this->assertSame(1, $fresh->delivery_attempts, 'attempt counter bumped');
        $this->assertNotNull($fresh->delivery_next_try_at, 'next try scheduled');
        $this->assertNull($fresh->delivery_failed_at, 'first failure does not give up');
        $this->assertSame('smtp down', $fresh->delivery_last_error);
        // Backoff is 5 min for attempt #1; allow a tiny scheduling slack.
        $expected = $before->copy()->addMinutes(5);
        $this->assertGreaterThanOrEqual(
            $expected->getTimestamp() - 2,
            $fresh->delivery_next_try_at->getTimestamp(),
            'next try scheduled ~5 min from now'
        );
        $this->assertLessThanOrEqual(
            $after->copy()->addMinutes(5)->getTimestamp() + 2,
            $fresh->delivery_next_try_at->getTimestamp()
        );
        // Balance is untouched.
        $this->assertEqualsWithDelta(50.0, $fresh->balance(), 0.001, 'balance preserved on delivery failure');
    }

    public function test_deliver_due_respects_backoff_window(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);
        // Drive attempt 1 to failure — backoff of 5 min lands the card OUTSIDE the deliverDue window.
        $this->armFailingTransport();
        Log::spy();
        GiftCardService::deliver($card->fresh());

        $this->assertNotNull($card->fresh()->delivery_next_try_at);
        // Time advances 1 minute — still inside the backoff window, so the tick must skip.
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->assertSame(0, GiftCardService::deliverDue(), 'card inside backoff is not retried');
        Carbon::setTestNow();
    }

    public function test_giving_up_after_max_attempts(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);
        $this->armFailingTransport();
        Log::spy();

        // Drive five consecutive failures. Each call records the attempt, so we do not need
        // to fake time advance — the next call goes through recordDeliveryFailure() which
        // counts the attempt and books the next window regardless of wall clock.
        for ($i = 1; $i <= GiftCardService::GIFT_CARD_MAX_DELIVERY_ATTEMPTS; $i++) {
            $this->assertFalse(GiftCardService::deliver($card->fresh()), "attempt #$i still failing");
        }

        $this->assertSame(
            GiftCardService::GIFT_CARD_MAX_DELIVERY_ATTEMPTS,
            $card->fresh()->delivery_attempts,
            'attempt counter caps at MAX'
        );
        $this->assertNotNull($card->fresh()->delivery_failed_at, 'delivery_failed_at set after MAX attempts');
        $this->assertNull($card->fresh()->delivery_next_try_at, 'no further backoff after give-up');
        $this->assertNull($card->fresh()->delivered_at, 'never marked delivered');
        $this->assertTrue($card->fresh()->isDeliveryFailed(), 'model helper agrees the card gave up');
        // Balance is still intact.
        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001, 'balance preserved across all retries');

        // A 6th call is a no-op; the card is permanently abandoned.
        $this->assertFalse(GiftCardService::deliver($card->fresh()));
        $this->assertSame(
            GiftCardService::GIFT_CARD_MAX_DELIVERY_ATTEMPTS,
            $card->fresh()->delivery_attempts,
            'no further counter bumps after give-up'
        );

        // deliverDue() must skip a permanently failed card.
        $this->assertSame(0, GiftCardService::deliverDue());
    }

    public function test_resend_resets_attempts_and_retries_immediately(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);
        $this->armFailingTransport();
        Log::spy();

        // Burn through MAX attempts — the card is permanently failed.
        for ($i = 0; $i < GiftCardService::GIFT_CARD_MAX_DELIVERY_ATTEMPTS; $i++) {
            GiftCardService::deliver($card->fresh());
        }
        $this->assertTrue($card->fresh()->isDeliveryFailed());

        // Owner hits Resend — failure bookkeeping is cleared, fresh attempt budget is granted,
        // and the call attempts delivery immediately (still failing here, so it re-records).
        $this->assertFalse(GiftCardService::resend($card->fresh()), 'still failing transport: resend is a no-op');
        $fresh = $card->fresh();
        $this->assertSame(1, $fresh->delivery_attempts, 'attempts counter reset and bumped');
        $this->assertNull($fresh->delivery_failed_at, 'failed_at cleared by resend');
        $this->assertNotNull($fresh->delivery_next_try_at, 'backoff window rescheduled');
        $this->assertEqualsWithDelta(50.0, $fresh->balance(), 0.001, 'balance still intact after resend');
    }

    public function test_resend_after_owner_fixes_the_address_succeeds(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id, 'bad@x.com');
        $this->armFailingTransport();
        Log::spy();

        // Burn all attempts so the owner sees the "not delivered" notice on screen.
        for ($i = 0; $i < GiftCardService::GIFT_CARD_MAX_DELIVERY_ATTEMPTS; $i++) {
            GiftCardService::deliver($card->fresh());
        }
        $this->assertTrue($card->fresh()->isDeliveryFailed());

        // Restore the working transport, owner updates the address and resends.
        // Install Mail::fake() (not Mail::swap of the original mailer) so the queued
        // GiftCardMail is captured for Mail::assertQueued — the original Mailer has no
        // assertQueued() method. Mail::fake() also satisfies the "transport is up"
        // contract: a queued email is a delivered-from-the-test's-POV email.
        Mail::swap($this->failingMailerBackup);
        $this->failingMailerBackup = null;
        Mail::fake();
        $card->update(['recipient_email' => 'good@x.com']);
        $this->assertTrue(GiftCardService::resend($card->fresh()), 'resend delivers on a working transport');
        $fresh = $card->fresh();
        $this->assertNotNull($fresh->delivered_at, 'delivered_at set on successful resend');
        $this->assertSame(0, $fresh->delivery_attempts, 'attempt counter cleared on successful resend');
        $this->assertNull($fresh->delivery_failed_at, 'failed_at cleared on successful resend');
        Mail::assertQueued(GiftCardMail::class, 1);
    }

    public function test_deliver_due_only_retries_cards_inside_the_backoff_window(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);
        $this->armFailingTransport();
        Log::spy();

        // First failure → +5 min.
        GiftCardService::deliver($card->fresh());
        $card->fresh();

        // 4 minutes in: still inside the backoff window, the tick must skip.
        Carbon::setTestNow(Carbon::now()->addMinutes(4));
        $this->assertSame(0, GiftCardService::deliverDue(), 'card still in backoff is skipped');
        Carbon::setTestNow();

        // 6 minutes in: past the +5 min window. The transport is still failing — the
        // call books attempt #2 and a +10 min backoff; deliverDue returns 0 because
        // nothing was delivered.
        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->assertSame(0, GiftCardService::deliverDue(), 'window closed, second attempt fails too');
        Carbon::setTestNow();
        $this->assertSame(2, $card->fresh()->delivery_attempts);
        $this->assertNotNull($card->fresh()->delivery_next_try_at);
    }

    public function test_owner_resend_endpoint_flashes_quality_alert(): void
    {
        [$owner, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);
        $this->armFailingTransport();
        Log::spy();

        $url = route('stores.giftcards.resend', $card->id).'?store_id='.$r->id;
        $this->actingAs($owner)->post($url)->assertRedirect();

        $this->assertSame('error', session('app_alert_type'), 'failing transport → error quick alert');
        $this->assertStringContainsString('not delivered', (string) session('app_alert_message'));
        $this->assertSame(1, $card->fresh()->delivery_attempts); // resend reset the burst; one fresh failure booked
    }

    public function test_owner_resend_endpoint_succeeds_when_transport_is_up(): void
    {
        [$owner, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);

        Mail::fake();
        $url = route('stores.giftcards.resend', $card->id).'?store_id='.$r->id;
        $this->actingAs($owner)->post($url)->assertRedirect();

        $this->assertStringContainsString('resent', (string) session('app_alert_message'));
        $this->assertNotNull($card->fresh()->delivered_at);
        Mail::assertQueued(GiftCardMail::class, 1);
    }

    public function test_index_renders_delivery_notice_for_failed_cards(): void
    {
        [$owner, $r] = $this->ownerAndRestaurant();
        $card = $this->activeCard($r->id);
        $this->armFailingTransport();
        Log::spy();

        for ($i = 0; $i < GiftCardService::GIFT_CARD_MAX_DELIVERY_ATTEMPTS; $i++) {
            GiftCardService::deliver($card->fresh());
        }

        // Render the owner index — the per-row notice and the resend button must be present.
        $html = $this->actingAs($owner)
            ->get(route('stores.giftcards.index').'?store_id='.$r->id)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-delivery-notice="'.$card->id.'"', $html, 'notice renders for failed cards');
        $this->assertStringContainsString('data-resend-form="'.$card->id.'"', $html, 'resend button renders for failed cards');
    }

    public function test_tenant_isolation_blocks_foreign_card_resend(): void
    {
        [$ownerA, $rA] = $this->ownerAndRestaurant();
        [, $rB] = $this->ownerAndRestaurant();
        $foreign = $this->activeCard($rB->id);

        $url = route('stores.giftcards.resend', $foreign->id).'?store_id='.$rA->id;
        $this->actingAs($ownerA)->post($url)->assertNotFound();
        $this->assertNull($foreign->fresh()->delivered_at);
    }
}
