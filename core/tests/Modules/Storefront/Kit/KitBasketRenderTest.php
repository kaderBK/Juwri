<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPicking;
use Juwri\Modules\Storefront\Services\Theme\KitView;
use Tests\Support\BasketStore;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-09: the `basket` (grocery) theme's REAL Blade views from the real view model of Fresh Mart — the store page (header, order type,
 * category tiles, the catalogue cards with pack size and price per kg, Add), the weighed product sheet (weight chips, estimate and cap
 * note, the out-of-stock choice carried as the line's substitution), the checkout dialog (delivery days and hourly slots with their
 * capacity, express, the order's out-of-stock choice), and the tracking page with "Changes to review" and its IDOR guard.
 */
class KitBasketRenderTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private const SLUG = 'fresh-mart-demo';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(BasketStore::clock());
        $this->store = BasketStore::make()->store;
    }

    public function test_the_store_page_is_the_basket_theme_with_its_cards_tiles_and_panels(): void
    {
        $html = $this->get('/'.self::SLUG)->assertOk()->getContent();

        foreach (['data-theme-slug="basket"', 'p-home', 'c-header', 'data-section="category_nav"', 'c-tiles', 'data-section="catalog"', 'c-pcard', 'c-cartpanel', 'c-cartbar--basket'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // a pack shows its size and what a kilo costs; a weighed product its price per kg and a way to choose the weight
        $this->assertMatchesRegularExpression('/c-pcard__size[^>]*>.*?250 g · .*?\/ kg/su', $html);
        $this->assertStringContainsString('Choose weight', $html);
        // the plain products add straight away; each has a stepper that appears once it is in the basket
        $this->assertStringContainsString('data-juwri-add="'.ApiFixtures::BANANAS.'"', $html);
        $this->assertStringContainsString('data-bk-stepper', $html);
        // one choice per product: the weighed one has no direct Add
        $this->assertStringNotContainsString('data-juwri-add="'.ApiFixtures::TOMATOES.'"', $html);
        // the endpoints of the grocery checkout are in the boot data the core reads (JSON escapes the slashes)
        $boot = str_replace('\\/', '/', $html);
        $this->assertStringContainsString('/'.self::SLUG.'/grocery/slots', $boot);
        $this->assertStringContainsString('/'.self::SLUG.'/retail/quote', $boot);
    }

    public function test_the_weighed_product_sheet_has_weight_chips_the_estimate_note_and_the_out_of_stock_choice(): void
    {
        $html = $this->get('/'.self::SLUG.'?fragment=item&item='.ApiFixtures::TOMATOES)->assertOk()->getContent();

        $this->assertStringContainsString('id="item-sheet"', $html);
        foreach (['c-group--weight', 'c-chip-opts', '250 g', '500 g', '1 kg', '2 kg', 'data-bk-estimate'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('Estimated', $html);
        $this->assertStringContainsString('never more than', $html);
        // the weight is a priced option of the line; the out-of-stock choice is not (it rides as `substitution`)
        $this->assertSame(4, preg_match_all('/data-group="'.ApiFixtures::TOMATO_WEIGHT_GROUP.'"/', $html));
        $this->assertSame(3, preg_match_all('/data-kit-substitution/', $html));
        $this->assertStringNotContainsString('data-group="'.ApiFixtures::SUBSTITUTION_GROUP.'"', $html);
        foreach (['Replace with similar', 'Call me', 'Remove it', 'We call you before replacing', 'You get a refund for it'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('c-sheet__approx', $html, 'the Add total of a weighed item is marked as an estimate');
        $this->assertMatchesRegularExpression('/data-kit-add-total>\s*SAR 2\.95/u', preg_replace('/[\x{2066}-\x{2069}]/u', '', $html));
    }

    public function test_the_checkout_dialog_offers_the_days_the_hourly_slots_and_express(): void
    {
        $html = $this->get('/'.self::SLUG)->assertOk()->getContent();

        $this->assertStringContainsString('id="checkout"', $html);
        foreach (['data-bk-slots', 'role="tablist"', 'c-daytab', 'c-slots', 'c-express', 'data-bk-express'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('11:00 – 12:00', $html);
        $this->assertMatchesRegularExpression('/c-slot is-full"[^>]*for="slot-[^"]+">.*?13:00 – 14:00/su', $html, 'a full hour cannot be picked');
        $this->assertStringContainsString('Express · 25–35 min', $html);
        $this->assertStringContainsString('Times are in Riyadh time (GMT+3)', $html);
        // the order's out-of-stock choice, the payment methods, the address of a delivery
        $this->assertStringContainsString('name="substitution_preference"', $html);
        $this->assertStringContainsString('name="pay_via"', $html);
        $this->assertStringContainsString('name="line1"', $html);
        $this->assertStringContainsString('name="country" value="SA"', $html);
    }

    public function test_the_tracking_page_asks_the_shopper_about_a_proposed_replacement_and_only_for_their_own_order(): void
    {
        [$order, $line] = $this->orderWithProposal();

        $html = $this->get(route('store.orderTrack', ['token' => $order->track_token]))->assertOk()->getContent();
        foreach (['data-theme-slug="basket"', 'Changes to review', 'c-review', 'Raspberries', 'Replaces Strawberries', 'data-bk-answer="kept"', 'data-bk-answer="refused"', 'Keep replacement', 'Refund instead', 'c-stepbar', 'Picking'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('1 of 2 items picked', $html);
        $this->assertStringContainsString('data-url="'.route('store.orderReplacement', ['token' => $order->track_token], false).'"', $html);
        $this->assertStringNotContainsString('data-bk-answer="kept" data-line="'.$line->id.'"', $html, 'no row id leaves the server: the line is ln_N');
        $this->assertStringContainsString('data-line="ln_2"', $html);

        // the answer is posted against the VM's own line id of THIS order
        $other = $this->orderWithProposal()[0];
        $this->postJson(route('store.orderReplacement', ['token' => $other->track_token]), ['line' => 'ln_9', 'answer' => 'kept'])->assertNotFound();
        $this->postJson(route('store.orderReplacement', ['token' => $order->track_token]), ['line' => 'ln_2', 'answer' => 'refused'])->assertOk()->assertJsonPath('answer', 'refused');
        $this->assertSame('removed', $line->fresh()->pick_status);
        $this->assertSame('proposed', OrderItem::query()->where('order_id', $other->id)->where('pick_status', 'proposed')->firstOrFail()->pick_status, "the other order's line did not change");
        $again = $this->get(route('store.orderTrack', ['token' => $order->track_token]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Changes to review', $again);
    }

    /** @return array{0: Order, 1: OrderItem} an order of two lines in picking, the second one replaced by a proposal */
    private function orderWithProposal(): array
    {
        $scenario = new ViewModelScenario($this->store, 'basket');
        $order = $scenario->place([
            ['item' => ApiFixtures::BANANAS, 'qty' => 1, 'options' => []],
            ['item' => '019f3fab-0380-73c2-89a4-21d8a38a1c3f', 'qty' => 1, 'options' => []],
        ], ['type' => 'delivery', 'address' => 'Al Olaya, King Fahd Rd, Bldg 12']);
        $picking = app(GroceryPicking::class);
        $picking->start($order);
        $items = $order->items()->orderBy('id')->get();
        $picking->pickAsOrdered($order, (int) $items[0]->id);
        $picking->propose($order, (int) $items[1]->id, 'Raspberries 125 g', 1250, null, false);

        return [$order->fresh() ?? $order, $items[1]];
    }

    public function test_the_states_page_and_the_closed_store_say_what_happened_and_offer_a_way_out(): void
    {
        $vm = (new ViewModelScenario($this->store, 'basket'))->build('en');
        $html = html_entity_decode(view('store_templates.basket.states', ['vm' => $vm, 'kit' => KitView::forVm($vm), 'theme' => 'basket', 'head' => [], 'assetVersion' => '1'])->render(), ENT_QUOTES);
        foreach (['Your basket is empty', 'Slots full today', 'Out of delivery zone', 'Card hold failed', "You weren't charged", 'Waiting for payment', 'Payment not completed', 'Offline'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }
}
