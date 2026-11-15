<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\GiftCard;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StubController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Storefront\Drops\GiftCardsDrop;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The gift-card PURCHASE side, as a storefront meets it.
 */
class GiftCardStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Mail::fake();
        $this->withoutMiddleware();
    }

    private function store(string $storeType = 'clothing', bool $giftCards = true): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'gc-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'Gift Store', 'sub_title' => 'Shop', 'description' => 'Clothes',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'store_type' => $storeType,
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        if ($giftCards) {
            StoreOption::updateStoreOption($store->id, 'gift_cards_enabled', '1');
            StoreOption::updateStoreOption($store->id, 'gift_card_presets', '25,50,100');
            StoreOption::updateStoreOption($store->id, 'gift_card_min', '5');
            StoreOption::updateStoreOption($store->id, 'gift_card_max', '500');
        }

        return $store;
    }

    private function enableStripe(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_title', 'Card');
    }

    public function test_the_drop_tells_the_storefront_what_it_can_sell(): void
    {
        $store = $this->store();
        $this->enableStripe($store);

        $drop = new GiftCardsDrop($store);

        $this->assertTrue($drop->enabled());
        $this->assertSame([25.0, 50.0, 100.0], $drop->presets());
        $this->assertSame(5.0, $drop->min());
        $this->assertSame(500.0, $drop->max());
        $this->assertSame('SAR', $drop->currency());
        $this->assertContains(['id' => 'stripe', 'title' => 'Card'], $drop->gateways(),
            'the store\'s installed payment methods are offered to the storefront');
        $this->assertStringContainsString('/gift-cards', $drop->url());
        $this->assertIsString($drop->csrf(), 'the form needs a CSRF token, even if this render has no session');
    }

    public function test_a_store_without_gift_cards_sells_none(): void
    {
        $drop = new GiftCardsDrop($this->store('clothing', false));

        $this->assertFalse($drop->enabled());
    }

    public function test_a_storefront_purchase_settles_into_an_active_card(): void
    {
        $store = $this->store();
        $this->enableStripe($store);
        config(['app.e2e_stubs' => true]);

        $res = $this->post(route('store.giftcards.purchase', $store->id), [
            'amount' => 50,
            'payment_method' => 'stripe',
            'recipient_email' => 'friend@example.com',
            'message' => 'Happy birthday',
        ]);

        $this->assertNotSame(500, $res->getStatusCode(), 'the purchase must not error');

        $card = GiftCard::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($card, 'the purchase created no card');
        $txn = $card->transaction_id ? Transaction::find($card->transaction_id) : null;
        $this->assertNotNull($txn);
        $this->assertSame('gift_card_purchase', $txn->transaction_method);
        $this->assertSame(50.0, (float) $txn->amount);

        StubController::pay($txn);

        $card->refresh();
        $this->assertSame('active', $card->status, 'the card was not activated by the settlement');
        $this->assertStringStartsWith('GC-', $card->code);
        $this->assertSame('friend@example.com', $card->recipient_email);
        $this->assertSame('Happy birthday', $card->message);
        $this->assertGreaterThan(0, (float) $card->balance(), 'the card was not funded');
    }

    public function test_a_purchase_below_the_stores_minimum_is_refused(): void
    {
        $store = $this->store();
        $this->enableStripe($store);

        $this->post(route('store.giftcards.purchase', $store->id), [
            'amount' => 1,
            'payment_method' => 'stripe',
            'recipient_email' => 'friend@example.com',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, GiftCard::where('store_id', $store->id)->count());
    }

    public function test_a_store_that_does_not_sell_gift_cards_has_no_buy_page(): void
    {
        $store = $this->store('clothing', false);

        $this->get(route('store.giftcards.buy', $store->id))->assertNotFound();
    }

    public function test_theme_context_exposes_gift_cards_drop_to_liquid(): void
    {
        $store = $this->store();
        $this->enableStripe($store);

        $globals = app(ThemeContext::class)->build([
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [], 'menuBanners' => collect(),
            'branches' => collect(), 'flags' => [], 'extras' => [],
        ]);
        $registers = app(ThemeContext::class)->registers([
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [],
        ]);

        $this->assertInstanceOf(GiftCardsDrop::class, $globals['giftCards']);

        $template = '{% if giftCards.enabled %}<form action="{{ giftCards.url }}">'
            .'{% for p in giftCards.presets %}<button data-mb-gc-preset="{{ p }}">{{ p }}</button>{% endfor %}'
            .'{% for g in giftCards.gateways %}<option value="{{ g.id }}">{{ g.title }}</option>{% endfor %}'
            .'</form>{% endif %}';

        $html = app(LiquidRenderer::class)->render($template, $globals, 'upload', $registers);

        $this->assertStringContainsString(route('store.giftcards.purchase', $store->id), $html);
        $this->assertStringContainsString('data-mb-gc-preset="25"', $html);
        $this->assertStringContainsString('data-mb-gc-preset="100"', $html);
        $this->assertStringContainsString('Card', $html);
    }

    public function test_commerce_runtime_bundles_include_gc_preset_handler(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $path = base_path('../assets/commerce-runtime/'.$version.'/commerce.js');
            $this->assertFileExists($path);
            $js = (string) file_get_contents($path);
            $this->assertStringContainsString('data-mb-gc-preset', $js);
            $this->assertStringContainsString('data-mb-gc-amount', $js);
        }
    }
}
