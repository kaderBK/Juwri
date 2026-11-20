<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController;
use Tests\TestCase;

/**
 * #22 guest order-history lookup. Regression for the schema bug found during
 * the B6 browser pass: the endpoint queried `phone`/`email`/`total`/`token`
 * columns that don't exist (real columns: `phone_number`, `track_token`, a
 * computed `total` accessor, and email only via the linked customer), so every
 * lookup 500'd. B6's account-panel order history rides this route.
 */
class GuestOrderLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin LTR: the API `total` uses store_money_plain, whose order is locale-sensitive
        // (RTL flips to number-first). This test pins the sign+decimals, not RTL position.
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makePost(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_lookup_by_phone_returns_order_with_a_track_url_once_the_phone_is_verified(): void
    {
        $store = $this->makePost();
        StoreOption::updateStoreOption($store->id, 'currency_sign', '£');

        Order::create([
            'store_id' => $store->id, 'phone_number' => '+96599112233',
            'status' => 'pending', 'type' => 'takeaway',
            'track_token' => 'TOK123ABC', 'created_at' => now(),
        ]);
        $normalized = app(SmsService::class)->normalizePhone('+96599112233');
        $this->withSession([CheckoutOtpController::SESSION_KEY => [$normalized => [(int) $store->id => time()]]]);

        $resp = $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => '+96599112233']);

        $resp->assertOk()->assertJson(['success' => true]);
        $resp->assertJsonPath('orders.0.token', 'TOK123ABC');
        $resp->assertJsonPath('orders.0.total', '£ 0.00'); // no items → store_money_plain: sign + space + zeroed accessor
        $resp->assertJsonPath('orders.0.track_url', route('store.orderTrack', 'TOK123ABC'));
    }

    /** AUDIT-authz #2: knowing a diner's phone is not proof — no bearer track token. */
    public function test_unverified_phone_lookup_shows_status_but_no_track_token(): void
    {
        $store = $this->makePost();
        Order::create([
            'store_id' => $store->id, 'phone_number' => '0912345678',
            'status' => 'pending', 'type' => 'takeaway',
            'track_token' => 'TOK123ABC', 'created_at' => now(),
        ]);

        $resp = $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => '0912345678'])
            ->assertOk()->assertJson(['success' => true, 'verify_to_track' => true]);
        $resp->assertJsonPath('orders.0.status', 'pending');
        $resp->assertJsonPath('orders.0.token', null);
        $this->assertStringNotContainsString('TOK123ABC', $resp->getContent());
        // SEC-1120: the guest still gets a Track link, to the signed status-only page (never the bearer track page).
        $trackUrl = (string) $resp->json('orders.0.track_url');
        $this->assertStringContainsString('/status', $trackUrl);
        $this->assertStringContainsString('signature=', $trackUrl);
    }

    /**
     * SEC-1120: the status-only page a guest reaches from the lookup shows the order number and status, and nothing the
     * lookup itself did not already show (no items, no amount, no track token, no actions). The link is signed: another
     * order id, or a link without its signature, is refused.
     */
    public function test_status_only_link_shows_the_status_and_cannot_be_retargeted(): void
    {
        $store = $this->makePost();
        $order = Order::create([
            'store_id' => $store->id, 'phone_number' => '0912345678',
            'status' => 'preparing', 'type' => 'takeaway',
            'track_token' => 'TOKSTATUS', 'created_at' => now(),
        ]);
        $other = Order::create([
            'store_id' => $store->id, 'phone_number' => '0900000000',
            'status' => 'ready', 'type' => 'takeaway',
            'track_token' => 'TOKOTHER', 'created_at' => now(),
        ]);

        $trackUrl = (string) $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => '0912345678'])
            ->assertOk()->json('orders.0.track_url');

        $page = $this->get($trackUrl)->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $page->assertSee('#'.$order->id)->assertSee('Preparing');
        $this->assertStringNotContainsString('TOKSTATUS', $page->getContent());

        $this->get(str_replace('/'.$order->id.'/', '/'.$other->id.'/', $trackUrl))->assertForbidden();
        $this->get(route('store.orderStatusOnly', $order->id))->assertForbidden();
    }

    /** SEC-1120 delta NIT 2: the status-only link lasts one day; after that it is refused. */
    public function test_status_only_link_expires_after_a_day(): void
    {
        $store = $this->makePost();
        Order::create([
            'store_id' => $store->id, 'phone_number' => '0912345678',
            'status' => 'preparing', 'type' => 'takeaway',
            'track_token' => 'TOKEXPIRE', 'created_at' => now(),
        ]);

        $trackUrl = (string) $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => '0912345678'])
            ->assertOk()->json('orders.0.track_url');
        $this->get($trackUrl)->assertOk();

        $this->travel(25)->hours();
        $this->get($trackUrl)->assertForbidden();
    }

    /** AUDIT-authz #2: a sequential order number alone reveals the status only. */
    public function test_order_number_lookup_without_proof_reveals_status_only(): void
    {
        $store = $this->makePost();
        StoreOption::updateStoreOption($store->id, 'order_lookup_by_number', '1');
        $order = Order::create([
            'store_id' => $store->id, 'phone_number' => '0912345678',
            'status' => 'preparing', 'type' => 'takeaway',
            'track_token' => 'TOKNUM', 'created_at' => now(),
        ]);

        $resp = $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => (string) $order->id])
            ->assertOk()->assertJson(['success' => true]);
        $resp->assertJsonPath('orders.0.status', 'preparing');
        $resp->assertJsonMissingPath('orders.0.total');
        $this->assertStringNotContainsString('TOKNUM', $resp->getContent());
    }

    public function test_lookup_by_email_matches_through_the_linked_customer(): void
    {
        $store = $this->makePost();
        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'Diner', 'email' => 'diner@ex.co', 'phone' => '0999',
        ]);
        Order::create([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'phone_number' => '0999',
            'status' => 'completed', 'type' => 'delivery',
            'track_token' => 'TOKEMAIL', 'created_at' => now(),
        ]);

        // A stranger who knows the e-mail sees the order but not its track token…
        $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => 'diner@ex.co'])
            ->assertOk()->assertJsonPath('orders.0.status', 'completed')->assertJsonPath('orders.0.token', null);

        // …the signed-in owner of the order gets it.
        $this->actingAs($customer, 'customer')
            ->postJson(route('store.orderLookup', $store->slug), ['identifier' => 'diner@ex.co'])
            ->assertOk()->assertJsonPath('orders.0.token', 'TOKEMAIL');
    }

    public function test_unknown_identifier_reports_no_orders(): void
    {
        $store = $this->makePost();

        $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => 'nobody@nowhere.co'])
            ->assertOk()->assertJson(['success' => false]);
    }
}
