<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Kitchen\Services\OrderBoard\DailyTokenService;
use Tests\TestCase;

/**
 * PARITY-RUN T2-2: the public board page and its polling endpoint.
 */
class OrderBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_order_board' => true]);
        DailyTokenService::clearMemo();
        FeatureAccess::flush();
        $this->withoutMiddleware(Installed::class);
    }

    private function storeFixture(array $options = []): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#0ea5e9',
            'title' => 'Board Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options + ['order_board_enabled' => '1'] as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    private function order(Store $store, string $status, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $store->id,
            'status' => $status,
            'type' => 'takeaway',
            'customer_name' => 'Alice Wong',
            'phone_number' => '+218911111111',
        ], $attrs));
    }

    public function test_board_page_and_state_render_the_two_columns(): void
    {
        $r = $this->storeFixture();
        $prep = $this->order($r, 'preparing');
        $ready = $this->order($r, 'new');
        $ready->update(['status' => 'ready']);
        $this->order($r, 'delivered'); // finished — off the board

        $this->get('/board/'.$r->slug)
            ->assertOk()
            ->assertSee('Preparing')
            ->assertSee('Ready')
            ->assertSee('Board Diner');

        $res = $this->getJson('/board/'.$r->slug.'/state')->assertOk();
        $this->assertSame([(int) $prep->fresh()->token_number], $res->json('preparing'));
        $this->assertSame([(int) $ready->fresh()->token_number], $res->json('ready'));
    }

    /**
     * The board swaps between the token columns and the idle card using the
     * `hidden` ATTRIBUTE, whose display:none comes from the UA stylesheet — so
     * any author `display` rule on those elements beats it. Both `main`
     * (display:grid) and `.idle` (display:flex) set one, and without an explicit
     * override the live board showed the "No orders" card sitting underneath
     * three live tokens. Caught in the browser, not by a request assertion; this
     * pins the override so it cannot be dropped again.
     */
    public function test_the_hidden_attribute_actually_hides_on_the_board(): void
    {
        $r = $this->storeFixture();
        $this->order($r, 'preparing');

        $html = $this->get('/board/'.$r->slug)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/\[hidden\]\s*\{[^}]*display:\s*none\s*!important/i',
            $html,
            'The board must force display:none for [hidden]; main and .idle both set a display that would otherwise win.'
        );
    }

    /** The whole point of a public screen: it may expose numbers and nothing else. */
    public function test_state_payload_carries_no_pii(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'preparing', ['customer_name' => 'Zaphod Beeblebrox', 'address' => '42 Galaxy Way']);

        $res = $this->getJson('/board/'.$r->slug.'/state')->assertOk();
        $body = $res->getContent();

        $this->assertStringNotContainsString('Zaphod', $body);
        $this->assertStringNotContainsString('Beeblebrox', $body);
        $this->assertStringNotContainsString('Galaxy', $body);
        $this->assertStringNotContainsString('911111111', $body);
        // Token numbers only — not even the order id, which is a guessable
        // handle to a real row on every other endpoint.
        $this->assertSame([(int) $order->fresh()->token_number], $res->json('preparing'));
        $this->assertSame(['preparing', 'ready', 'date', 'config'], array_keys($res->json()));
        foreach ($res->json('preparing') as $entry) {
            $this->assertIsInt($entry);
        }
    }

    public function test_board_is_scoped_to_its_store_and_branch(): void
    {
        $r = $this->storeFixture();
        $other = $this->storeFixture();
        $branchA = Branch::create(['store_id' => $r->id, 'name' => 'A', 'position' => 1]);
        $branchB = Branch::create(['store_id' => $r->id, 'name' => 'B', 'position' => 2]);

        $a = $this->order($r, 'preparing', ['branch_id' => $branchA->id]);
        $b = $this->order($r, 'preparing', ['branch_id' => $branchB->id]);
        $this->order($other, 'preparing');

        $res = $this->getJson('/board/'.$r->slug.'/state?branch='.$branchA->id)->assertOk();
        $this->assertSame([(int) $a->fresh()->token_number], $res->json('preparing'));

        $res = $this->getJson('/board/'.$r->slug.'/state?branch='.$branchB->id)->assertOk();
        $this->assertSame([(int) $b->fresh()->token_number], $res->json('preparing'));

        // Store-wide view shows both, and never the other store's order.
        $res = $this->getJson('/board/'.$r->slug.'/state')->assertOk();
        $this->assertCount(2, $res->json('preparing'));
    }

    public function test_a_branch_from_another_store_is_ignored(): void
    {
        $r = $this->storeFixture();
        $other = $this->storeFixture();
        $foreign = Branch::create(['store_id' => $other->id, 'name' => 'X', 'position' => 1]);
        $this->order($r, 'preparing');

        // Falls back to the store-wide board rather than reading across tenants.
        $res = $this->getJson('/board/'.$r->slug.'/state?branch='.$foreign->id)->assertOk();
        $this->assertCount(1, $res->json('preparing'));
    }

    public function test_ready_entries_recycle_off_the_board(): void
    {
        $r = $this->storeFixture(['order_board_ready_minutes' => '5']);
        $order = $this->order($r, 'preparing');
        $order->update(['status' => 'ready']);

        $this->assertCount(1, $this->getJson('/board/'.$r->slug.'/state')->json('ready'));

        $order->forceFill(['ready_at' => Carbon::now()->subMinutes(9)])->saveQuietly();
        $this->assertCount(0, $this->getJson('/board/'.$r->slug.'/state')->json('ready'));

        // 0 = keep it up until the order leaves the ready state.
        StoreOption::updateStoreOption($r->id, 'order_board_ready_minutes', '0');
        $this->assertCount(1, $this->getJson('/board/'.$r->slug.'/state')->json('ready'));
    }

    public function test_yesterdays_numbers_never_appear_on_todays_board(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'preparing');
        $order->forceFill(['token_date' => Carbon::now()->subDay()->format('Y-m-d')])->saveQuietly();

        $this->assertCount(0, $this->getJson('/board/'.$r->slug.'/state')->json('preparing'));
    }

    public function test_orders_without_a_number_are_invisible(): void
    {
        // Delivery is not opted in by default, so it never reaches the board.
        $r = $this->storeFixture();
        $this->order($r, 'preparing', ['type' => 'delivery']);

        $res = $this->getJson('/board/'.$r->slug.'/state')->assertOk();
        $this->assertSame([], $res->json('preparing'));
        $this->assertSame([], $res->json('ready'));
    }

    public function test_board_404s_when_the_store_never_turned_it_on(): void
    {
        $r = $this->storeFixture(['order_board_enabled' => '0']);

        $this->get('/board/'.$r->slug)->assertNotFound();
        $this->getJson('/board/'.$r->slug.'/state')->assertNotFound();
    }

    public function test_unknown_store_404s(): void
    {
        $this->get('/board/no-such-store-'.Str::lower(Str::random(5)))->assertNotFound();
    }

    /**
     * The board is anonymous: no login, no session, no device token. If a
     * feature: middleware ever gets attached to it this test fails, because a
     * guest would get a 403 instead of the board.
     */
    public function test_board_is_reachable_by_an_anonymous_visitor(): void
    {
        $r = $this->storeFixture();
        $this->order($r, 'preparing');

        $this->assertGuest();
        $this->get('/board/'.$r->slug)->assertOk();
    }

    /** Arabic is a first-class board locale — the page must flip direction. */
    public function test_board_renders_right_to_left_in_arabic(): void
    {
        $r = $this->storeFixture();
        $this->order($r, 'preparing');

        app()->setLocale('ar');
        $res = $this->get('/board/'.$r->slug)->assertOk();

        $res->assertSeeHtml('dir="rtl"');
        $res->assertSeeHtml('لوحة الطلبات'); // "Order board" (page title)
        $res->assertDontSee('Preparing');
    }

    public function test_scheduled_but_unreleased_orders_stay_off_the_board(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'preparing', ['scheduled_at' => Carbon::now()->addHours(3)]);

        $this->assertNotNull($order->fresh()->token_number);
        $this->assertSame([], $this->getJson('/board/'.$r->slug.'/state')->json('preparing'));

        $order->forceFill(['released_at' => now()])->saveQuietly();
        $this->assertCount(1, $this->getJson('/board/'.$r->slug.'/state')->json('preparing'));
    }
}
