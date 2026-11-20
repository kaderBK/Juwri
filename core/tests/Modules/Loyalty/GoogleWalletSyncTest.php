<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Jobs\SyncGoogleWalletPass;
use Tests\Support\FakeGoogleServiceAccount;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Lane C — C3. Balance-mutating loyalty + gift-card seams dispatch SyncGoogleWalletPass
 * when (and only when) the store has Google Wallet enabled, and the job itself is
 * fail-soft (never bubbles, no network unless faked).
 */
class GoogleWalletSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = FakeGoogleServiceAccount::path();
    }

    private function enableCreds(): void
    {
        config([
            'services.google_wallet.issuer_id' => '3388000000012345678',
            'services.google_wallet.service_account_json' => $this->fixture,
        ]);
    }

    private function disableCreds(): void
    {
        config([
            'services.google_wallet.issuer_id' => '',
            'services.google_wallet.service_account_json' => '',
        ]);
    }

    private function store(bool $toggleOn = true): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', $toggleOn ? '1' : '0');

        return $store;
    }

    private function pointsProgram(Store $store): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5.0, 'active' => true,
        ]);
    }

    public function test_loyalty_earn_dispatches_when_enabled(): void
    {
        Queue::fake();
        $this->enableCreds();
        $store = $this->store(true);
        $this->pointsProgram($store);
        $customer = Customer::create(['name' => 'A', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);

        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(rand(1, 999999), 10, 'seed');

        Queue::assertPushed(SyncGoogleWalletPass::class, function ($job) use ($store, $customer) {
            return $job->type === 'loyalty'
                && $job->storeId === (int) $store->id
                && $job->subjectId === (int) $customer->id;
        });
    }

    public function test_loyalty_earn_silent_when_creds_absent(): void
    {
        Queue::fake();
        $this->disableCreds();
        $store = $this->store(true);
        $this->pointsProgram($store);
        $customer = Customer::create(['name' => 'A', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);

        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(rand(1, 999999), 10, 'seed');

        Queue::assertNotPushed(SyncGoogleWalletPass::class);
    }

    public function test_loyalty_earn_silent_when_store_toggle_off(): void
    {
        Queue::fake();
        $this->enableCreds();
        $store = $this->store(false); // creds present, but store toggle OFF
        $this->pointsProgram($store);
        $customer = Customer::create(['name' => 'A', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);

        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(rand(1, 999999), 10, 'seed');

        Queue::assertNotPushed(SyncGoogleWalletPass::class);
    }

    public function test_gift_card_issue_and_adjust_dispatch(): void
    {
        Queue::fake();
        $this->enableCreds();
        $store = $this->store(true);

        $card = GiftCardService::issueManual($store->id, 25.0);
        GiftCardService::adjust($card->fresh(), 5.0, 'manual');

        Queue::assertPushed(SyncGoogleWalletPass::class, function ($job) use ($store, $card) {
            return $job->type === 'giftcard'
                && $job->storeId === (int) $store->id
                && $job->subjectId === (int) $card->id;
        });
        // issueManual + adjust = 2 dispatches for this card.
        Queue::assertPushed(SyncGoogleWalletPass::class, 2);
    }

    /**
     * ML-1120 review NIT 5: a gift-card reversal inside an outer transaction (OrderCancellation::reverseTenders) queues
     * the pass sync only once that transaction commits: a rollback leaves the pass alone.
     */
    public function test_gift_card_reversal_syncs_the_pass_only_after_the_outer_commit(): void
    {
        Queue::fake();
        $this->enableCreds();
        $store = $this->store(true);
        $card = GiftCardService::issueManual($store->id, 25.0);
        Queue::assertPushed(SyncGoogleWalletPass::class, 1);
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 1, 'payment_method' => 'cash']);
        $order->forceFill(['gift_card_id' => $card->id, 'gift_card_applied' => 10.0])->save();

        try {
            DB::transaction(function () use ($order) {
                $this->assertGreaterThan(0, GiftCardService::reverseRedemption($order->fresh()));
                throw new \RuntimeException('a later step failed');
            });
        } catch (\RuntimeException) {
        }
        Queue::assertPushed(SyncGoogleWalletPass::class, 1); // rolled back: no sync

        GiftCardService::reverseRedemption($order->fresh());
        Queue::assertPushed(SyncGoogleWalletPass::class, 2);
    }

    public function test_gift_card_silent_when_toggle_off(): void
    {
        Queue::fake();
        $this->enableCreds();
        $store = $this->store(false);

        GiftCardService::issueManual($store->id, 25.0);

        Queue::assertNotPushed(SyncGoogleWalletPass::class);
    }

    public function test_job_patches_object_end_to_end_with_faked_http(): void
    {
        $this->enableCreds();
        $store = $this->store(true);
        $this->pointsProgram($store);
        $customer = Customer::create(['name' => 'A', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(rand(1, 999999), 10, 'seed');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake'], 200),
            'walletobjects.googleapis.com/*' => Http::response(['id' => 'x'], 200),
        ]);

        new SyncGoogleWalletPass('loyalty', (int) $store->id, (int) $customer->id)->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'));
    }

    public function test_job_never_bubbles_on_error(): void
    {
        $this->enableCreds();
        $store = $this->store(true);
        $this->pointsProgram($store);
        $customer = Customer::create(['name' => 'A', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(rand(1, 999999), 10, 'seed');

        // No Http::fake — the token call hits preventStrayRequests and throws; the job
        // must swallow it (fail-soft) rather than surfacing.
        new SyncGoogleWalletPass('loyalty', (int) $store->id, (int) $customer->id)->handle();

        $this->assertTrue(true); // reached here → no exception bubbled
    }

    public function test_job_no_network_when_store_disabled(): void
    {
        $this->enableCreds();
        $store = $this->store(false); // toggle off
        Http::fake();

        new SyncGoogleWalletPass('loyalty', (int) $store->id, 1)->handle();

        Http::assertNothingSent();
    }
}
