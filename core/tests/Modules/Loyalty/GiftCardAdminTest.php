<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class GiftCardAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        // The giftcards index extends the owner layout, which reads $settings — the
        // ViewServiceProvider only shares it when APP_INSTALLED is set (unset in tests).
        $this->shareLayoutGlobals();
        $this->withoutMiddleware();
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

    public function test_issue_manual_funds_ledger_and_activates(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 30.0, []);
        $this->assertSame('active', $card->status);
        $this->assertEqualsWithDelta(30.0, $card->balance(), 0.001);
    }

    public function test_void_writes_off_balance_and_disables(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 40.0, []);
        GiftCardService::void($card);
        $card->refresh();
        $this->assertSame('disabled', $card->status);
        $this->assertEqualsWithDelta(0.0, $card->balance(), 0.001);
        $this->assertFalse($card->isRedeemable());
    }

    public function test_adjust_changes_balance_both_directions(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 20.0, []);
        GiftCardService::adjust($card, 15.0, 'goodwill');
        $this->assertEqualsWithDelta(35.0, $card->fresh()->balance(), 0.001);
        GiftCardService::adjust($card->fresh(), -10.0, 'correction');
        $this->assertEqualsWithDelta(25.0, $card->fresh()->balance(), 0.001);
    }

    /** B1: a double-submitted adjustment with the SAME per-render nonce applies once. */
    public function test_adjust_same_token_credits_once(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 20.0, []);

        // Two POSTs of one form render carry the same token = one double-click.
        GiftCardService::adjust($card, 15.0, 'goodwill', 'nonce-abc');
        GiftCardService::adjust($card->fresh(), 15.0, 'goodwill', 'nonce-abc');

        // Applied once: 20 + 15 = 35 (pre-fix the time-based ref differs each call → 50).
        $this->assertEqualsWithDelta(35.0, $card->fresh()->balance(), 0.001);
    }

    /** B1: two DIFFERENT nonces are two deliberate adjustments — both apply. */
    public function test_adjust_different_tokens_both_apply(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 20.0, []);

        GiftCardService::adjust($card, 15.0, 'goodwill', 'nonce-1');
        GiftCardService::adjust($card->fresh(), 15.0, 'goodwill', 'nonce-2');

        $this->assertEqualsWithDelta(50.0, $card->fresh()->balance(), 0.001);
    }

    /**
     * B1: a repeated DEBIT with the same nonce is a no-op and must NOT throw the funds
     * guard, even though the balance already dropped below the (idempotent) amount.
     */
    public function test_adjust_same_token_debits_once_without_throwing(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 20.0, []);

        GiftCardService::adjust($card, -15.0, 'correction', 'nonce-d');
        // Balance is now 5; a fresh -15 debit would fail the funds check — the replay
        // short-circuit must return cleanly (pre-fix: new ref → ValidationException).
        GiftCardService::adjust($card->fresh(), -15.0, 'correction', 'nonce-d');

        $this->assertEqualsWithDelta(5.0, $card->fresh()->balance(), 0.001);
    }

    /** B1: the adjust ENDPOINT double-posted with one form's nonce charges once. */
    public function test_adjust_endpoint_double_submit_applies_once(): void
    {
        [$owner, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 20.0, []);
        $token = 'form-nonce-xyz';
        $url = route('stores.giftcards.adjust', $card->id).'?store_id='.$r->id;

        $this->actingAs($owner)->post($url, ['delta' => 15.0, 'reason' => 'goodwill', 'idempotency_token' => $token]);
        $this->actingAs($owner)->post($url, ['delta' => 15.0, 'reason' => 'goodwill', 'idempotency_token' => $token]);

        $this->assertEqualsWithDelta(35.0, $card->fresh()->balance(), 0.001);
    }

    public function test_liability_sums_active_card_balances(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        GiftCardService::issueManual($r->id, 50.0, []);
        $b = GiftCardService::issueManual($r->id, 30.0, []);
        GiftCardService::void($b); // no longer counts
        GiftCardService::issueManual($r->id, 20.0, []);

        $this->assertEqualsWithDelta(70.0, GiftCardService::liabilityFor($r->id), 0.001);
    }

    public function test_liability_excludes_expired_cards(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        GiftCardService::issueManual($r->id, 50.0, []); // active, no expiry
        $expired = GiftCardService::issueManual($r->id, 30.0, []);
        $expired->update(['expires_at' => now()->subDay()]); // lapsed but status stays 'active'

        // The expired card's balance is dead value — it must not inflate the liability.
        $this->assertFalse($expired->fresh()->isRedeemable());
        $this->assertEqualsWithDelta(50.0, GiftCardService::liabilityFor($r->id), 0.001);
    }

    public function test_adjust_debit_beyond_balance_is_rejected_cleanly(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $card = GiftCardService::issueManual($r->id, 20.0, []);

        // Before the fix this hit the ledger funds check → raw RuntimeException → 500.
        try {
            GiftCardService::adjust($card, -50.0, 'overdraw');
            $this->fail('debiting beyond the balance must be rejected');
        } catch (ValidationException $e) {
            // expected — a clean form error, not a 500
        }

        $this->assertEqualsWithDelta(20.0, $card->fresh()->balance(), 0.001, 'balance unchanged after a rejected debit');
    }

    /** B2: the expiry sweep flips a lapsed active card to 'expired'; a live card is untouched. */
    public function test_expire_sweep_flips_lapsed_active_cards(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $lapsed = GiftCardService::issueManual($r->id, 30.0, []);
        $lapsed->update(['expires_at' => now()->subDay()]); // past expiry, status still 'active'
        $live = GiftCardService::issueManual($r->id, 40.0, []); // no expiry
        $future = GiftCardService::issueManual($r->id, 25.0, []);
        $future->update(['expires_at' => now()->addMonth()]);

        $flipped = GiftCardService::expireLapsed();

        $this->assertSame(1, $flipped, 'only the lapsed card is swept');
        $this->assertSame('expired', $lapsed->fresh()->status);
        $this->assertSame('active', $live->fresh()->status, 'no-expiry card untouched');
        $this->assertSame('active', $future->fresh()->status, 'future-expiry card untouched');

        // Idempotent: a second sweep flips nothing.
        $this->assertSame(0, GiftCardService::expireLapsed());

        // Liability already excluded the lapsed card by date; it stays excluded by status.
        $this->assertEqualsWithDelta(65.0, GiftCardService::liabilityFor($r->id), 0.001);
    }

    /** B2: the scheduled command runs the sweep. */
    public function test_expire_command_sweeps(): void
    {
        [, $r] = $this->ownerAndRestaurant();
        $lapsed = GiftCardService::issueManual($r->id, 30.0, []);
        $lapsed->update(['expires_at' => now()->subDay()]);

        $this->artisan('giftcards:expire')->assertExitCode(0);

        $this->assertSame('expired', $lapsed->fresh()->status);
    }

    /** C5: an expired card shows the expired pill, and the status filter isolates them. */
    public function test_expired_card_shows_pill_and_status_filter(): void
    {
        [$owner, $r] = $this->ownerAndRestaurant();
        $active = GiftCardService::issueManual($r->id, 30.0, []);
        $lapsed = GiftCardService::issueManual($r->id, 20.0, []);
        $lapsed->update(['expires_at' => now()->subDay()]);
        GiftCardService::expireLapsed(); // B2 sweep flips $lapsed → 'expired'

        // The index shows the expired pill.
        $html = $this->actingAs($owner)
            ->get(route('stores.giftcards.index').'?store_id='.$r->id)
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-status-pill="expired"', $html, 'the expired pill renders');

        // Filtering by status=expired lists the lapsed card and hides the active one.
        $filtered = $this->actingAs($owner)
            ->get(route('stores.giftcards.index').'?store_id='.$r->id.'&status=expired')
            ->assertOk()->getContent();
        $this->assertStringContainsString($lapsed->code, $filtered, 'expired card listed under the expired filter');
        $this->assertStringNotContainsString($active->code, $filtered, 'active card hidden by the expired filter');
    }

    public function test_index_is_tenant_scoped(): void
    {
        [$ownerA, $rA] = $this->ownerAndRestaurant();
        [, $rB] = $this->ownerAndRestaurant();
        $foreign = GiftCardService::issueManual($rB->id, 25.0, []);

        // Owner A voiding owner B's card → 404.
        $this->actingAs($ownerA)
            ->post(route('stores.giftcards.void', $foreign->id).'?store_id='.$rA->id)
            ->assertNotFound();
        $this->assertSame('active', $foreign->fresh()->status);
    }
}
