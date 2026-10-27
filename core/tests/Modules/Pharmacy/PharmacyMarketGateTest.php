<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P1-23 — Acceptable Use Policy 3.1, made true in code:
 *  (a) pharmacy is off by default and opened per country (PHARMACY_ENABLED_COUNTRIES);
 *  (b) a pharmacy is published only after the platform approved its licence upload, and only until that licence
 *      expires (expiry unpublishes it without any job);
 *  (c) a per-market cap per medicine and order, which the store can only lower;
 *  (d) no coupons on medicines where the market forbids promotions.
 * Every other store type is untouched.
 */
class PharmacyMarketGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['pharmacy.enabled_countries' => [], 'pharmacy.markets' => [], 'pharmacy.quantity_cap' => 2, 'pharmacy.block_medicine_promotions' => false]);
    }

    private function owner(string $country = 'SA'): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => 1], 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'country_code' => $country]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function store(User $owner, string $type = 'pharmacy'): Store
    {
        $title = 'S'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'shelf');

        return $store;
    }

    private function item(Store $store, string $name = 'Paracetamol 500mg', array $extra = []): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Pain relief']);

        return Menu::create(array_merge(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => 5, 'active' => 1], $extra));
    }

    private function gate(): SalesRestrictions
    {
        return app(SalesRestrictions::class);
    }

    public function test_pharmacy_is_off_by_default_and_opens_per_country_with_an_approved_licence(): void
    {
        $this->assertSame([], PharmacyMarkets::enabledCountries(), 'no country is open unless the operator opens it');
        $store = $this->store($this->owner('SA'));

        $this->assertSame('pharmacy_market_disabled', $this->gate()->unpublishedReason($store));

        config(['pharmacy.enabled_countries' => ['SA']]);
        $this->assertSame('pharmacy_licence_missing', $this->gate()->unpublishedReason($store));

        PharmacyMarket::licence($store, PharmacyLicence::PENDING);
        $this->assertSame('pharmacy_licence_pending', $this->gate()->unpublishedReason($store));

        PharmacyMarket::licence($store, PharmacyLicence::REJECTED);
        $this->assertSame('pharmacy_licence_rejected', $this->gate()->unpublishedReason($store));

        PharmacyMarket::licence($store, PharmacyLicence::APPROVED);
        $this->assertNull($this->gate()->unpublishedReason($store));

        // The licence's country is the market: a licence for a country that is not open does not publish.
        PharmacyMarket::licence($store, PharmacyLicence::APPROVED, 'AE');
        $this->assertSame('pharmacy_market_disabled', $this->gate()->unpublishedReason($store));
    }

    public function test_an_unknown_market_is_unpublished_and_other_store_types_are_never_restricted(): void
    {
        $owner = $this->owner('');
        $this->assertSame('pharmacy_market_unknown', $this->gate()->unpublishedReason($this->store($owner)));

        $retail = $this->store($owner, 'retail');
        $store = $this->store($owner, 'restaurant');
        foreach ([$retail, $store] as $s) {
            $this->assertNull($this->gate()->unpublishedReason($s));
            $this->assertNull($this->gate()->orderRefusal($s, [['menu' => $this->item($s), 'quantity' => 50]]));
            $this->assertFalse($this->gate()->promotionsBlocked($s, [$this->item($s, 'X')]));
        }
    }

    public function test_licence_expiry_unpublishes_the_store_the_next_day(): void
    {
        config(['pharmacy.enabled_countries' => ['SA']]);
        $store = $this->store($this->owner('SA'));
        PharmacyMarket::licence($store, PharmacyLicence::APPROVED, 'SA', now()->toDateString());
        $this->assertNull($this->gate()->unpublishedReason($store), 'valid through its expiry date');

        $this->travel(1)->days();
        $this->assertSame('pharmacy_licence_expired', $this->gate()->unpublishedReason($store));
    }

    public function test_unpublished_pharmacy_storefront_is_hidden_from_customers_but_previewable_by_its_owner(): void
    {
        $owner = $this->owner('SA');
        $store = $this->store($owner);
        $this->item($store);

        $this->get(route('publicView', $store->slug))->assertNotFound();
        $this->actingAs($this->owner('SA'))->get(route('publicView', $store->slug))->assertNotFound();
        $this->actingAs($owner)->get(route('publicView', $store->slug))->assertOk();

        PharmacyMarket::open($store);
        auth()->logout();
        $this->get(route('publicView', $store->slug))->assertOk()->assertSee('Paracetamol 500mg');
    }

    public function test_the_public_ai_catalog_does_not_list_an_unpublished_pharmacy(): void
    {
        $store = $this->store($this->owner('SA'));
        $this->item($store, 'Ibuprofen 200mg');
        $call = fn (string $tool, array $args) => json_decode((string) $this->postJson(route('mcp.catalog'), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args],
        ])->json('result.content.0.text'), true);

        $this->assertSame(0, $call('search_stores', ['query' => $store->title])['count'] ?? null);
        $this->assertStringNotContainsString('Ibuprofen', json_encode($call('search_items', ['query' => 'Ibuprofen'])));

        PharmacyMarket::open($store);
        $this->assertSame(1, $call('search_stores', ['query' => $store->title])['count'] ?? null);
        $this->assertStringContainsString('Ibuprofen', json_encode($call('search_items', ['query' => 'Ibuprofen'])));
    }

    public function test_a_customer_order_is_refused_while_unpublished_and_above_the_market_cap(): void
    {
        $owner = $this->owner('SA');
        $store = $this->store($owner);
        $medicine = $this->item($store);
        $vitamins = $this->item($store, 'Hand cream', ['is_medicine' => false]);

        try {
            OrderApiService::create($owner->id, $store->id, ['source' => 'qr', 'type' => 'pickup_slot', 'items' => [['item_id' => $medicine->id, 'quantity' => 1]]]);
            $this->fail('an unpublished pharmacy must not take a customer order');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not available for online orders', implode(' ', $e->validator->errors()->all()));
        }

        PharmacyMarket::open($store);
        try {
            OrderApiService::create($owner->id, $store->id, ['source' => 'qr', 'type' => 'pickup_slot', 'items' => [
                ['item_id' => $medicine->id, 'quantity' => 2], ['item_id' => $medicine->id, 'quantity' => 1],
            ]]);
            $this->fail('3 units of one medicine exceed the cap of 2 (lines are summed)');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at most 2 of "Paracetamol 500mg"', implode(' ', $e->validator->errors()->all()));
        }

        // A product the owner marked as not a medicine is not capped.
        $this->assertNull($this->gate()->orderRefusal($store, [['menu' => $vitamins, 'quantity' => 9], ['menu' => $medicine, 'quantity' => 2]]));
    }

    public function test_the_market_cap_applies_and_the_store_can_only_lower_it(): void
    {
        config(['pharmacy.markets' => ['SA' => ['quantity_cap' => 3]]]);
        $owner = $this->owner('SA');
        $store = $this->store($owner);
        PharmacyMarket::open($store);
        $markets = app(PharmacyMarkets::class);
        $this->assertSame(3, $markets->quantityCap($store));

        $this->actingAs($owner)->put(route('pharmacy.compliance.settings', $store), ['quantity_cap' => 10])->assertRedirect();
        $this->assertSame(3, $markets->quantityCap($store), 'a store cannot raise the market cap');
        $this->assertSame('3', (string) store_options($store->id, PharmacyMarkets::OPTION_QUANTITY_CAP));

        $this->actingAs($owner)->put(route('pharmacy.compliance.settings', $store), ['quantity_cap' => 1])->assertRedirect();
        $this->assertSame(1, $markets->quantityCap($store->fresh()));
    }

    public function test_add_to_cart_refuses_a_medicine_above_the_cap_and_an_unpublished_pharmacy(): void
    {
        $store = $this->store($this->owner('SA'));
        $medicine = $this->item($store);
        $cart = app(GoodsCartService::class);

        try {
            $cart->addLine($store, 'tok-1', $medicine->id, null, 1);
            $this->fail('unpublished');
        } catch (ValidationException) {
        }

        PharmacyMarket::open($store);
        $line = $cart->addLine($store, 'tok-1', $medicine->id, null, 2);
        $this->expectException(ValidationException::class);
        $cart->updateLineQuantity($store, 'tok-1', $line->id, 3);
    }

    public function test_no_coupon_on_medicines_where_the_market_forbids_promotions(): void
    {
        $owner = $this->owner('SA');
        $store = $this->store($owner);
        PharmacyMarket::open($store);
        $medicine = $this->item($store);
        $customer = Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        $coupon = Coupon::create([
            'code' => 'MED-'.rand(1000, 9999), 'percentage' => 20, 'limit' => 99, 'used' => 0, 'user_id' => $owner->id,
            'coupon_type' => 'percentage', 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth(),
        ]);
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $medicine->id, 'quantity' => 2, 'variation' => 0]);

        $out = OrderPricing::applyCouponAndCredit($order->fresh(), ['coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 10.0, 'customer_id' => $customer->id]);
        $this->assertSame(0.0, (float) $out['discount']);
        $this->assertSame('promotions_restricted', $out['claim_denied_reason']);
        $this->assertNull($order->fresh()->coupon_id);

        // A market that allows promotions lets the same coupon through.
        config(['pharmacy.markets' => ['SA' => ['promotions' => true]]]);
        $this->assertFalse($this->gate()->promotionsBlocked($store, [$medicine]));
    }

    /**
     * Found by the e2eA pharmacy journey: the storefront coupon pre-flight answered "Coupon applied, -0.45" for a
     * medicine, then the placed order was settled at full price (the coupon step refuses it). The diner must be told
     * at the pre-flight, with the cart lines the themes post.
     */
    public function test_the_storefront_coupon_preflight_refuses_a_code_on_medicines_up_front(): void
    {
        $owner = $this->owner('SA');
        $store = $this->store($owner);
        PharmacyMarket::open($store);
        $medicine = $this->item($store);
        $plasters = $this->item($store, 'Plasters', ['is_medicine' => 0]);
        $coupon = Coupon::create([
            'code' => 'PRE-'.rand(1000, 9999), 'percentage' => 20, 'limit' => 99, 'used' => 0, 'user_id' => $owner->id,
            'coupon_type' => 'percentage', 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth(),
        ]);
        $check = fn (Menu $m) => $this->postJson(route('store.applyCoupon', $store), [
            'code' => $coupon->code, 'subtotal' => 10, 'items' => json_encode([['id' => $m->id, 'quantity' => 1]]),
        ]);

        $check($medicine)->assertOk()->assertJson(['success' => false, 'message' => 'Promo codes cannot be used on orders that contain medicines.']);
        $check($plasters)->assertOk()->assertJson(['success' => true]);

        // A market that allows promotions lets the medicine through, as settlement does.
        config(['pharmacy.markets' => ['SA' => ['promotions' => true]]]);
        $check($medicine)->assertOk()->assertJson(['success' => true]);
    }

    public function test_owner_uploads_a_licence_which_stays_unpublished_until_the_platform_approves_it(): void
    {
        Storage::fake('local');
        config(['pharmacy.enabled_countries' => ['SA']]);
        $owner = $this->owner('SA');
        $store = $this->store($owner);

        $this->actingAs($owner)->get(route('pharmacy.compliance.index', $store))->assertOk()->assertSee('Licence and publishing');

        $this->actingAs($owner)->post(route('pharmacy.compliance.licence', $store), [
            'country' => 'sa', 'licence_number' => 'PH-123', 'issuing_authority' => 'Ministry of Health',
            'expires_on' => now()->addYear()->toDateString(), 'document' => UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        ])->assertRedirect(route('pharmacy.compliance.index', $store));

        $licence = PharmacyLicence::latestFor($store->id);
        $this->assertNotNull($licence);
        $this->assertSame(PharmacyLicence::PENDING, $licence->status);
        $this->assertSame('SA', $licence->country);
        Storage::disk('local')->assertExists($licence->document_path);
        $this->assertSame('pharmacy_licence_pending', $this->gate()->unpublishedReason($store));

        // An expired licence or a non-document file is refused.
        $this->actingAs($owner)->post(route('pharmacy.compliance.licence', $store), [
            'country' => 'SA', 'licence_number' => 'PH-1', 'issuing_authority' => 'MoH', 'expires_on' => now()->subDay()->toDateString(),
            'document' => UploadedFile::fake()->create('x.exe', 10),
        ])->assertSessionHasErrors(['expires_on', 'document']);

        // Another owner cannot upload for this store.
        $this->actingAs($this->owner('SA'))->post(route('pharmacy.compliance.licence', $store), [
            'country' => 'SA', 'licence_number' => 'X', 'issuing_authority' => 'X', 'expires_on' => now()->addYear()->toDateString(),
            'document' => UploadedFile::fake()->create('l.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        // The platform approves → published; a rejection needs a reason and unpublishes again.
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($admin)->post(route('admin.pharmacy-licences.approve', $licence->id))->assertRedirect(route('admin.pharmacy-licences.index'));
        $this->assertSame(PharmacyLicence::APPROVED, $licence->fresh()->status);
        $this->assertSame($admin->id, $licence->fresh()->reviewed_by);
        $this->assertNull($this->gate()->unpublishedReason($store));

        $this->actingAs($admin)->post(route('admin.pharmacy-licences.reject', $licence->id), [])->assertSessionHasErrors('review_note');
        $this->actingAs($admin)->post(route('admin.pharmacy-licences.reject', $licence->id), ['review_note' => 'Document unreadable'])->assertRedirect();
        $this->assertSame('pharmacy_licence_rejected', $this->gate()->unpublishedReason($store));

        // Owners cannot reach the review surface.
        $this->actingAs($owner)->post(route('admin.pharmacy-licences.approve', $licence->id));
        $this->assertSame(PharmacyLicence::REJECTED, $licence->fresh()->status);
    }

    public function test_medicine_flag_defaults_on_and_the_owner_can_mark_a_product_as_not_a_medicine(): void
    {
        $owner = $this->owner('SA');
        $store = $this->store($owner);
        $item = $this->item($store);
        $this->assertTrue((bool) $item->fresh()->is_medicine);

        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), ['not_medicine' => '1'])->assertRedirect();
        $this->assertFalse((bool) $item->fresh()->is_medicine);

        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), [])->assertRedirect();
        $this->assertTrue((bool) $item->fresh()->is_medicine);
    }
}
