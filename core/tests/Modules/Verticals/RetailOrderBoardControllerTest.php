<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * rev/multi-vertical B5 — retail order states + board.
 *
 * A retail/laundry order advances new -> packing -> ready -> shipped|
 * collected -> done, timestamping each step, on a board relabeled per
 * vertical (never "kitchen"). KDS/OrderStatus stay untouched: they are a
 * different pipeline a non-food order never enters.
 */
class RetailOrderBoardControllerTest extends TestCase
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
    }

    private function retailStoreWithOrder(string $type = 'ship'): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
        $order = Order::create(['store_id' => $store->id, 'type' => $type, 'status' => 'new']);

        return [$owner, $store, $order];
    }

    public function test_board_shows_the_order_under_new_and_uses_vertical_vocab(): void
    {
        [$owner, $store, $order] = $this->retailStoreWithOrder();

        $res = $this->actingAs($owner)->get(route('stores.orders.board', ['store_id' => $store->id]));

        $res->assertOk();
        $res->assertSee('#'.$order->id);
        // goods vocab (config/verticals.php) board word — THIS page's own
        // heading is "Fulfilment", never the food word "Kitchen" the KDS
        // screen uses (a sidebar nav link to that separate, untouched screen
        // may still be present elsewhere on the layout — scope the assertion
        // to the page_title heading itself, not the whole rendered page).
        preg_match('/<h1 class="ui-page-head__title">(.*?)<\/h1>/s', $res->getContent(), $matches);
        $this->assertSame('Fulfilment', trim($matches[1] ?? ''), 'page heading should use the goods board vocab word');
    }

    public function test_advancing_a_ship_order_moves_new_to_packing_to_ready_to_shipped_to_done(): void
    {
        [$owner, $store, $order] = $this->retailStoreWithOrder('ship');

        $advance = fn () => $this->actingAs($owner)->post(
            route('stores.orders.board.advance', $order->id).'?store_id='.$store->id
        );

        $advance()->assertRedirect();
        $order->refresh();
        $this->assertSame('packing', $order->status);
        $this->assertNotNull($order->packing_at);

        $advance();
        $order->refresh();
        $this->assertSame('ready', $order->status);
        $this->assertNotNull($order->ready_at);

        $advance();
        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertNotNull($order->shipped_at);

        $advance();
        $order->refresh();
        $this->assertSame('done', $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_a_pickup_order_lands_on_collected_not_shipped(): void
    {
        [$owner, $store, $order] = $this->retailStoreWithOrder('pickup_slot');

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($owner)->post(route('stores.orders.board.advance', $order->id).'?store_id='.$store->id);
        }

        $order->refresh();
        $this->assertSame('collected', $order->status);
        $this->assertNotNull($order->collected_at);
    }

    public function test_a_done_order_cannot_be_advanced_further(): void
    {
        [$owner, $store, $order] = $this->retailStoreWithOrder('ship');
        $order->update(['status' => 'done']);

        $res = $this->actingAs($owner)->post(route('stores.orders.board.advance', $order->id).'?store_id='.$store->id);

        $res->assertSessionHasErrors('status');
        $this->assertSame('done', $order->fresh()->status);
    }

    public function test_an_owner_cannot_advance_another_stores_order(): void
    {
        [, , $order] = $this->retailStoreWithOrder();
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $intruderStore = Store::factory()->create(['user_id' => $intruder->id, 'store_type' => 'retail']);

        $res = $this->actingAs($intruder)->post(
            route('stores.orders.board.advance', $order->id).'?store_id='.$intruderStore->id
        );

        $res->assertNotFound();
        $this->assertSame('new', $order->fresh()->status);
    }
}
