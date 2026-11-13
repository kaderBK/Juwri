<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Laundry\Models\LaundryHold;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Verticals\Services\Fulfillment\RetailOrderBoardService;
use Tests\Modules\Laundry\Concerns\LaundryHoldFixtures;
use Tests\TestCase;

/**
 * P3-11: the laundry on the `book` theme — services by the piece and by the kilo, the pickup and delivery times with ONE hold, the
 * confirm page, the order it places (the held pickup is its slot, the quote is its total) and its tracking page.
 */
class KitBookLaundryTest extends TestCase
{
    use LaundryHoldFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Store, 1: Menu, 2: Menu} */
    private function bookLaundry(array $options = []): array
    {
        $store = $this->laundryStore($options + ['store_template' => 'book', 'scheduling_slot_capacity' => 2]);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Laundry', 'active' => '1']);
        $shirt = $this->shirtIron($store);
        $wash = $this->washAndFold($store);
        Menu::query()->whereIn('id', [$shirt->id, $wash->id])->update(['category_id' => $category->id]);

        return [$store, $shirt, $wash];
    }

    public function test_the_store_page_lists_the_services_with_a_quantity_and_a_weight(): void
    {
        [$store, $shirt, $wash] = $this->bookLaundry();
        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-theme-slug="book"', $html);
        $this->assertStringContainsString('data-book-laundry', $html);
        $this->assertStringContainsString('name="lines['.$shirt->public_id.']"', $html);
        $this->assertStringContainsString('step="1"', $html, 'a per-piece service counts whole pieces');
        $this->assertStringContainsString('step="0.5"', $html, 'a per-kg service takes half kilos');
        $this->assertStringContainsString('per kg', $html);
        $this->assertStringContainsString('per piece', $html);
        $this->assertStringNotContainsString('data-juwri-boot', $html);
        $this->assertStringContainsString('action="/'.$store->slug.'/book/lines"', $html);
    }

    public function test_the_whole_laundry_flow_with_one_hold_for_pickup_and_delivery(): void
    {
        [$store, $shirt, $wash] = $this->bookLaundry();
        $slug = $store->slug;

        $this->post('/'.$slug.'/book/lines', ['lines' => [$shirt->public_id => '3', $wash->public_id => '2.5'], 'type' => 'delivery'])
            ->assertRedirect(route('laundry.book.schedule', ['slug' => $slug, 'type' => 'delivery']));

        $schedule = $this->get('/'.$slug.'/book/schedule?type=delivery&date=2026-10-08&return_date=2026-10-09')->assertOk()->getContent();
        $this->assertStringContainsString('p-laundry', $schedule);
        $this->assertStringContainsString('name="pickup"', $schedule);
        $this->assertStringContainsString('name="return"', $schedule);
        $this->assertMatchesRegularExpression('/2\.5\xC2\xA0kg/', $schedule, 'the weight, with its unit kept together');
        $this->assertStringContainsString('Delivery is at least 24 hours after pickup.', $schedule);
        // Shirt 3 x 3.50 + Wash & fold 2.5 kg x 5.00 = 23.00
        $this->assertStringContainsString('23.00', $schedule);

        $this->post('/'.$slug.'/book/hold', ['type' => 'delivery', 'pickup' => $this->slotId(1, '10:00'), 'return' => $this->slotId(2, '16:30')])->assertRedirect();
        $hold = LaundryHold::query()->firstOrFail();
        $this->assertSame(1, LaundryHold::query()->count(), 'one hold for both times');
        $this->assertNotNull($hold->return_at);

        $confirm = $this->get('/'.$slug.'/book/confirm/'.$hold->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('name="customer_name"', $confirm);
        $this->assertStringContainsString('name="phone_number"', $confirm);
        $this->assertStringContainsString('name="address"', $confirm);
        $this->assertStringContainsString('value="'.$hold->public_id.'"', $confirm);
        $this->assertStringContainsString('The final price is set when your laundry is weighed.', $confirm);
        $this->assertStringContainsString('23.00', $confirm);

        $place = $this->post('/'.$slug.'/book', [
            'type' => 'delivery', 'hold' => $hold->public_id, 'customer_name' => 'Noura Al-Harbi', 'phone_number' => '+966500000001', 'address' => '12 Olaya St',
        ]);
        $order = Order::query()->latest('id')->firstOrFail();
        $place->assertRedirect(route('store.orderTrack', $order->track_token));
        $this->assertSame('2026-10-08 10:00', $order->scheduled_at->utc()->format('Y-m-d H:i'), 'the held pickup is the order slot');
        $this->assertSame('used', $hold->fresh()->status);
        $this->assertSame($order->id, (int) $hold->fresh()->order_id);
        $this->assertSame(23.0, (float) $order->grand_total, 'quoted == booked');

        $track = $this->get(route('store.orderTrack', $order->track_token));
        $this->assertTrue(in_array($track->getStatusCode(), [200], true));
        $html = $track->getContent();
        $this->assertStringContainsString('data-theme-slug="book"', $html);
        $this->assertMatchesRegularExpression('/2\.5\xC2\xA0kg/', $html);
        $this->assertStringContainsString('Processing', $html);
        $this->assertStringContainsString('Out for delivery', $html);
    }

    public function test_a_chosen_time_stays_selectable_and_is_not_called_taken(): void
    {
        [$store, $shirt] = $this->bookLaundry();
        $slug = $store->slug;
        $this->post('/'.$slug.'/book/lines', ['lines' => [$shirt->public_id => '1'], 'type' => 'pickup_slot'])->assertRedirect();
        $pickup = $this->slotId(1, '11:00');

        $html = $this->get('/'.$slug.'/book/schedule?type=pickup_slot&date='.substr($pickup, 0, 10).'&pickup='.$pickup)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="pickup"[^>]*value="'.preg_quote($pickup, '/').'"[^>]*checked[^>]*>/', $html, 'the chosen time is checked');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="pickup"[^>]*value="'.preg_quote($pickup, '/').'"[^>]*disabled/', $html, 'and not disabled');
    }

    public function test_a_taken_slot_says_so_and_a_released_hold_gives_the_times_back(): void
    {
        [$store, $shirt] = $this->bookLaundry(['scheduling_slot_capacity' => 1]);
        $slug = $store->slug;
        $this->post('/'.$slug.'/book/lines', ['lines' => [$shirt->public_id => '1'], 'type' => 'pickup_slot'])->assertRedirect();
        $pickup = $this->slotId(1, '11:00');

        $this->post('/'.$slug.'/book/hold', ['type' => 'pickup_slot', 'pickup' => $pickup])->assertRedirect();
        $hold = LaundryHold::query()->firstOrFail();

        // another browser asks for the same slot: the page that sent it back says it is taken
        $this->flushSession();
        $this->from('/'.$slug.'/book/schedule?type=pickup_slot')->post('/'.$slug.'/book/hold', ['type' => 'pickup_slot', 'pickup' => $pickup])
            ->assertRedirect('/'.$slug.'/book/schedule?type=pickup_slot')->assertSessionHasErrors('hold');

        // the first browser lets it go; now the second gets it
        $this->withSession(['laundry.holds.'.$hold->public_id => 'wrong'])->post('/'.$slug.'/book/hold/'.$hold->public_id.'/release')->assertRedirect();
        $this->assertSame('active', $hold->fresh()->status, 'a hold is released only with its own token');
    }

    public function test_a_hold_that_is_gone_sends_the_customer_back_to_the_store_page_with_the_reason(): void
    {
        [$store, $shirt] = $this->bookLaundry();
        $slug = $store->slug;
        $this->post('/'.$slug.'/book/lines', ['lines' => [$shirt->public_id => '2'], 'type' => 'pickup_slot'])->assertRedirect();

        $res = $this->get('/'.$slug.'/book/confirm/'.Str::uuid());
        $res->assertRedirect(route('publicView', ['slug' => $slug]));
        $home = $this->followingRedirects()->get('/'.$slug.'/book/confirm/'.Str::uuid())->getContent();
        $this->assertStringContainsString('no longer held', $home);
    }

    public function test_the_laundry_board_reaches_out_for_delivery_and_the_tracking_page_follows(): void
    {
        [$store, $shirt] = $this->bookLaundry();
        $slug = $store->slug;
        $this->post('/'.$slug.'/book/lines', ['lines' => [$shirt->public_id => '2'], 'type' => 'delivery'])->assertRedirect();
        $this->post('/'.$slug.'/book/hold', ['type' => 'delivery', 'pickup' => $this->slotId(1, '10:00'), 'return' => $this->slotId(2, '12:00')])->assertRedirect();
        $hold = LaundryHold::query()->firstOrFail();
        $this->post('/'.$slug.'/book', ['type' => 'delivery', 'hold' => $hold->public_id, 'customer_name' => 'N', 'phone_number' => '+966500000001', 'address' => '12 Olaya St'])->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();

        $board = app(RetailOrderBoardService::class);
        foreach (['packing', 'ready', 'shipped'] as $state) {
            $order = $board->advance($order->fresh());
            $this->assertSame($state, $order->status);
        }
        $html = $this->get(route('store.orderTrack', $order->track_token))->assertOk()->getContent();
        $this->assertStringContainsString('Out for delivery', $html);
        $this->assertStringContainsString('c-steps__item--current', $html);
    }

    public function test_a_laundry_without_the_book_theme_keeps_the_plain_booking_page(): void
    {
        $store = $this->laundryStore(['store_template' => 'bookla']);
        $this->shirtIron($store);
        $this->get('/'.$store->slug.'/book/schedule')->assertRedirect();
        $this->get('/'.$store->slug.'/book')->assertOk();
    }
}
