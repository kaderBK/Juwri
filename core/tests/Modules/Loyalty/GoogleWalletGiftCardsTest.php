<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Tests\Support\FakeGoogleServiceAccount;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * google-suite Lane C follow-up — the gift-card "Add to Google Wallet" surface:
 * the account-page partial, the customer API endpoint, and the Customer MCP tool.
 * Every path is dark until the store enables Wallet; the save-link is minted only
 * for the PURCHASER of a still-redeemable card.
 */
class GoogleWalletGiftCardsTest extends TestCase
{
    use RefreshDatabase;

    private object $plan;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        config([
            'services.google_wallet.issuer_id' => '3388000000012345678',
            'services.google_wallet.service_account_json' => FakeGoogleServiceAccount::path(),
        ]);

        $this->plan = TestPlans::create([
            'name' => 'GC Wallet Test', 'description' => 'x',
            'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => ['allow_viewonly' => '1', 'allow_pro' => '1', 'restaurant_limit' => 1],
            'status' => 1,
        ]);

        // The full account page renders the storefront layout, whose view composer is
        // skipped when the app is not "installed" (test env). shareLayoutGlobals() is
        // the sanctioned fixture that seeds $settings/$activeTheme/etc.; the controller
        // itself supplies $plan (from the owner) into the account view.
        $this->shareLayoutGlobals();
    }

    private function store(bool $toggleOn = true): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => (string) $this->plan->id]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', $toggleOn ? '1' : '0');

        return $store;
    }

    private function customer(Store $store, string $name = 'C'): Customer
    {
        return Customer::create(['name' => $name, 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
    }

    // ── Customer API: GET /api/v1/customer/gift-cards ────────────────────────────

    public function test_api_lists_purchased_cards_with_wallet_url_when_enabled(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $card = GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $customer->id]);
        $token = CustomerToken::issue($customer);

        $res = $this->withToken($token)->getJson(route('api.v1.customer.gift-cards'));

        $res->assertOk()
            ->assertJsonPath('wallet_enabled', true)
            ->assertJsonPath('gift_cards.0.id', $card->id)
            ->assertJsonPath('gift_cards.0.code', $card->code);
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $res->json('gift_cards.0.wallet_save_url'));
    }

    public function test_api_wallet_url_null_when_disabled(): void
    {
        $store = $this->store(false);
        $customer = $this->customer($store);
        GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $customer->id]);
        $token = CustomerToken::issue($customer);

        $this->withToken($token)->getJson(route('api.v1.customer.gift-cards'))
            ->assertOk()
            ->assertJsonPath('wallet_enabled', false)
            ->assertJsonPath('gift_cards.0.wallet_save_url', null);
    }

    public function test_api_excludes_other_and_recipient_cards(): void
    {
        $store = $this->store();
        $me = $this->customer($store, 'Me');
        $other = $this->customer($store, 'Other');
        $mine = GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $me->id]);
        GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $other->id]); // not mine
        GiftCardService::issueManual($store->id, 30.0, []); // recipient card, no purchaser link
        $token = CustomerToken::issue($me);

        $res = $this->withToken($token)->getJson(route('api.v1.customer.gift-cards'))->assertOk();

        $this->assertCount(1, $res->json('gift_cards'));
        $this->assertSame($mine->id, $res->json('gift_cards.0.id'));
    }

    public function test_api_requires_token(): void
    {
        $this->getJson(route('api.v1.customer.gift-cards'))->assertUnauthorized();
    }

    // ── Customer MCP: my_gift_cards tool ─────────────────────────────────────────

    private function callTool(string $rawToken, string $name, array $args = []): TestResponse
    {
        return $this->postJson(route('mcp.customer'), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $args],
        ], ['Authorization' => 'Bearer '.$rawToken]);
    }

    private function toolPayload(TestResponse $res): array
    {
        return json_decode($res->json('result.content.0.text') ?? '[]', true) ?: [];
    }

    public function test_mcp_lists_the_tool(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $raw = CustomerToken::issue($customer);

        $res = $this->postJson(route('mcp.customer'), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ], ['Authorization' => 'Bearer '.$raw])->assertOk();

        $names = array_column($res->json('result.tools'), 'name');
        $this->assertContains('my_gift_cards', $names);
    }

    public function test_mcp_returns_own_cards_with_save_url(): void
    {
        $store = $this->store();
        $me = $this->customer($store, 'Me');
        $other = $this->customer($store, 'Other');
        $card = GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $me->id]);
        GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $other->id]);
        $raw = CustomerToken::issue($me);

        $rows = $this->toolPayload($this->callTool($raw, 'my_gift_cards'));

        $this->assertCount(1, $rows);
        $this->assertSame($card->code, $rows[0]['code']);
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $rows[0]['wallet_save_url']);
    }

    public function test_mcp_save_url_null_when_disabled(): void
    {
        $store = $this->store(false);
        $me = $this->customer($store);
        GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $me->id]);
        $raw = CustomerToken::issue($me);

        $rows = $this->toolPayload($this->callTool($raw, 'my_gift_cards'));

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['wallet_save_url']);
    }

    // ── Account-page partial (HTTP through the controller) ───────────────────────

    public function test_account_page_shows_block_when_enabled(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $card = GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $customer->id]);

        $html = $this->actingAs($customer, 'customer')
            ->get(route('customer.account', $store->id))
            ->assertOk()
            ->assertSee('mbGWGiftCards')   // the section
            ->assertSee('mbGWGCBtn')       // the per-card "Add to Google Wallet" button
            ->assertSee($card->code)       // the purchaser's own card code
            ->getContent();

        // The save-link JWT is minted lazily by the endpoint on click — never baked
        // into the account HTML (mirrors the loyalty badge contract).
        $this->assertStringNotContainsString('pay.google.com/gp/v/save/', $html);
    }

    public function test_account_page_hides_block_when_disabled(): void
    {
        $store = $this->store(false);
        $customer = $this->customer($store);
        GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $customer->id]);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.account', $store->id))
            ->assertOk()
            ->assertDontSee('mbGWGiftCards');
    }

    public function test_account_page_hides_block_when_no_cards(): void
    {
        $store = $this->store();
        $customer = $this->customer($store); // purchased nothing

        $this->actingAs($customer, 'customer')
            ->get(route('customer.account', $store->id))
            ->assertOk()
            ->assertDontSee('mbGWGiftCards');
    }
}
