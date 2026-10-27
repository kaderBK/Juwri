<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\GiftCard;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\GiftCardService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * KWD form-path precision (3 decimals). The storefront purchase form handled by
 * Juwri\Modules\Loyalty\Http\Controllers\User\GiftCardController::purchase must keep a
 * KWD amount's third decimal end to end: the pending GiftCard.initial_value and the linked
 * Transaction both carry 1.250 as 1.25 and 1.255 as 1.255. Before the fix the form path
 * rounded KWD to 2 decimals (1.255 -> 1.26). Redemption at one-fil precision must follow.
 */
class GiftCardKwdFormPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();

        config(['settings.admin_allow_gift_cards' => 1]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /**
     * Store + owner + menu, KWD, gift cards on, and a customer-facing Stripe gateway.
     *
     * @return array{owner: User, store: Store, menu: Menu}
     */
    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);

        StoreOption::updateStoreOption($store->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'KWD');
        // KWD min-amount default is 5; the form path under test is exercised at ~1.250.
        StoreOption::updateStoreOption($store->id, 'gift_card_min', '1');
        StoreOption::updateStoreOption($store->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', 0);

        // P1-23: a gift card is paid to the store's OWN gateway.
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_title', 'Card');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', 'sk_test_store_own');
        config(['settings.stripe_secret_key' => 'sk_test_PLATFORM']);

        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'store', 'menu');
    }

    /** The store's Stripe adapter reaches out on purchase; fake the Checkout Session. */
    private function fakeStripe(): void
    {
        // a distinct Checkout Session per purchase: a repeated session id is a unique-key clash (pgsql aborts the transaction)
        $n = 0;
        Http::fake(['api.stripe.com/*' => function () use (&$n) {
            $n++;

            return Http::response(['id' => 'cs_gc_'.$n, 'url' => 'https://checkout.stripe.test/cs_gc_'.$n,
                'payment_status' => 'unpaid', 'status' => 'open']);
        }]);
    }

    /** POST the purchase form and return the freshly created (pending) card. */
    private function purchaseViaForm(Store $store, string $amount): GiftCard
    {
        $this->post(route('store.giftcards.purchase', $store->id), [
            'amount' => $amount,
            'payment_method' => 'stripe',
        ])->assertRedirect();

        return GiftCard::where('store_id', $store->id)->latest('id')->firstOrFail();
    }

    public function test_purchase_form_keeps_kwd_third_decimal_on_card_and_transaction(): void
    {
        ['store' => $store] = $this->storeFixture();
        $this->fakeStripe();

        $card = $this->purchaseViaForm($store, '1.250');
        $txn = Transaction::findOrFail($card->transaction_id);

        $this->assertSame('pending', $card->status);
        $this->assertSame(1.25, (float) $card->initial_value); // 1.250 KWD stays 1.25, never 1.3
        $this->assertSame(1.25, (float) $txn->amount);
        $this->assertSame('KWD', $card->currency);

        // 1.255 is a legal 3-decimal KWD amount and must survive intact (before the fix: 1.26).
        $card255 = $this->purchaseViaForm($store, '1.255');
        $txn255 = Transaction::findOrFail($card255->transaction_id);

        $this->assertSame(1.255, (float) $card255->initial_value);
        $this->assertSame(1.255, (float) $txn255->amount);
    }

    public function test_activated_kwd_card_redeems_at_fil_precision(): void
    {
        ['owner' => $owner, 'store' => $store, 'menu' => $menu] = $this->storeFixture();

        // Activate the 1.250 card exactly the way GiftCardPurchaseTest does.
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate((int) $store->id, 1.250, 'stripe');
        GiftCardService::activate($card, $txn);

        $card = $card->fresh();
        $this->assertSame('active', $card->status);
        $this->assertSame(1.25, (float) $card->initial_value);
        $this->assertSame(1.25, $card->balance());

        $order = OrderApiService::create($owner->id, $store->id, [
            'type' => 'takeaway', 'source' => 'api',
            'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);

        $applied = GiftCardService::applyToOrder($card, $order->fresh(), 1.125);

        $this->assertSame(1.125, $applied);
        $this->assertSame(0.125, $card->fresh()->balance());
    }
}
