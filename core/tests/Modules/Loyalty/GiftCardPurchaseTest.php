<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Support\Fulfilment;
use Tests\Support\TestPlans;
use Tests\TestCase;

class GiftCardPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    private function storeFixture(): Store
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
        StoreOption::updateStoreOption($r->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');

        return $r;
    }

    public function test_generated_codes_are_unique_and_prefixed(): void
    {
        $a = GiftCardService::generateCode();
        $b = GiftCardService::generateCode();
        $this->assertStringStartsWith('GC-', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_initiate_creates_pending_card_linked_to_transaction(): void
    {
        $r = $this->storeFixture();
        $result = GiftCardService::initiate($r->id, 50.0, 'stripe', ['recipient_email' => 'r@x.com']);

        $this->assertSame('pending', $result['card']->status);
        $this->assertSame(0.0, $result['card']->balance()); // not funded yet
        $this->assertSame('gift_card_purchase', $result['transaction']->transaction_method);
        $this->assertSame($result['transaction']->id, $result['card']->transaction_id);
    }

    public function test_activation_funds_the_card_ledger_and_is_idempotent(): void
    {
        $r = $this->storeFixture();
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, 75.0, 'stripe');

        GiftCardService::activate($card, $txn);
        $card->refresh();
        $this->assertSame('active', $card->status);
        $this->assertEqualsWithDelta(75.0, $card->balance(), 0.001);
        $this->assertNotNull($card->issued_at);

        // Replay the gateway confirmation → no double funding.
        GiftCardService::activate($card->fresh(), $txn);
        $this->assertEqualsWithDelta(75.0, $card->fresh()->balance(), 0.001);

        // Ledger money invariant: card account balance == sum of its legs.
        $acct = $card->fresh()->ledgerAccount();
        $this->assertEqualsWithDelta(75.0, LedgerService::balanceFor($acct), 0.001);
    }

    public function test_checkout_choke_point_activates_gift_card(): void
    {
        $r = $this->storeFixture();
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, 40.0, 'stripe');

        // Simulate the gateway-confirmed one-time payment funnel.
        Fulfilment::fulfil($txn->fresh());

        $this->assertSame('active', $card->fresh()->status);
        $this->assertEqualsWithDelta(40.0, $card->fresh()->balance(), 0.001);
    }

    public function test_buy_page_404_when_disabled(): void
    {
        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'gift_cards_enabled', '0');

        $this->get(route('store.giftcards.buy', $r->id))->assertNotFound();
    }
}
