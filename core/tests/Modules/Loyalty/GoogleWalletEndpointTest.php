<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\FakeGoogleServiceAccount;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Lane C — C4. Save-link endpoints (customer-guarded, ownership-checked) + the
 * storefront partial's hidden-when-unconfigured gating.
 */
class GoogleWalletEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_wallet.issuer_id' => '3388000000012345678',
            'services.google_wallet.service_account_json' => FakeGoogleServiceAccount::path(),
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
        LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5.0, 'active' => true,
        ]);

        return $store;
    }

    private function customer(Store $store): Customer
    {
        return Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
    }

    public function test_loyalty_jwt_returns_save_url_for_authed_customer(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);

        $res = $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.googleWallet.loyaltyJwt', $store->id));

        $res->assertOk();
        $url = $res->json('save_url');
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);
    }

    /** Security (AC-1): no pass for a store the customer does not belong to. */
    public function test_loyalty_jwt_404_for_another_stores_customer(): void
    {
        $store = $this->store();
        $customer = $this->customer($this->store());

        $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.googleWallet.loyaltyJwt', $store->id))
            ->assertNotFound();
        $this->assertSame(0, LoyaltyAccount::where('store_id', $store->id)->count());
    }

    public function test_loyalty_jwt_404_when_store_toggle_off(): void
    {
        $store = $this->store(false);
        $customer = $this->customer($store);

        $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.googleWallet.loyaltyJwt', $store->id))
            ->assertNotFound()
            ->assertJson(['enabled' => false]);
    }

    public function test_loyalty_jwt_401_for_guest(): void
    {
        $store = $this->store();

        // The route is behind auth:customer, so a guest is redirected/denied (not 200).
        $this->getJson(route('publicView.googleWallet.loyaltyJwt', $store->id))
            ->assertUnauthorized();
    }

    public function test_gift_card_jwt_for_owner(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $card = GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $customer->id]);

        $res = $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.googleWallet.giftCardJwt', ['storeId' => $store->id, 'giftCardId' => $card->id]));

        $res->assertOk();
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $res->json('save_url'));
    }

    public function test_gift_card_jwt_403_for_non_owner(): void
    {
        $store = $this->store();
        $owner = $this->customer($store);
        $other = $this->customer($store);
        $card = GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $owner->id]);

        $this->actingAs($other, 'customer')
            ->getJson(route('publicView.googleWallet.giftCardJwt', ['storeId' => $store->id, 'giftCardId' => $card->id]))
            ->assertForbidden();
    }

    public function test_gift_card_jwt_404_for_wrong_store(): void
    {
        $storeA = $this->store();
        $storeB = $this->store();
        $customer = $this->customer($storeA);
        $card = GiftCardService::issueManual($storeA->id, 30.0, ['purchaser_customer_id' => $customer->id]);

        // Card belongs to store A, requested under store B → not found.
        $this->actingAs($customer, 'customer')
            ->getJson(route('publicView.googleWallet.giftCardJwt', ['storeId' => $storeB->id, 'giftCardId' => $card->id]))
            ->assertNotFound();
    }

    public function test_partial_hidden_when_store_disabled(): void
    {
        $store = $this->store(false);
        $customer = $this->customer($store);
        $this->actingAs($customer, 'customer');

        $html = $this->renderPartial($store);

        $this->assertStringNotContainsString('mbGWallet', $html);
    }

    public function test_partial_present_when_enabled_and_no_jwt_leaked(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $this->actingAs($customer, 'customer');

        $html = $this->renderPartial($store);

        $this->assertStringContainsString('mbGWallet', $html);
        // The JWT is minted lazily by the endpoint — never embedded in the HTML.
        $this->assertStringNotContainsString('pay.google.com/gp/v/save/', $html);
    }

    private function renderPartial(Store $store): string
    {
        return View::make('store_templates._partials.google-wallet', ['store' => $store])->render();
    }
}
