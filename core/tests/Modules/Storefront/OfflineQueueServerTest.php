<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Services\Storefront\OfflineQueuePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PinsMiddayClock;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-03 (server side, REVIEW Opus): the storefront core JS places the cart it quoted through the one checkout
 * (public-id `lines` -> the legacy fields), idempotently by the client UUIDv7 (J27-A2), and an order sent from the
 * offline queue is refused when ANY level (platform, reseller, store) switched the queue off, when its type /
 * payment may not queue, or when it waited past the store-set limit.
 */
class OfflineQueueServerTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The kit demo store is open 07:00–23:00 (store time): pin the clock to midday so the suite does not fail after 23:00.
        $this->travelTo(now()->setTime(12, 0));
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seed(KitDemoSeeder::class);
    }

    protected function tearDown(): void
    {
        $hooks = new \ReflectionProperty(Hooks::class, 'hooks');
        $all = $hooks->getValue();
        unset($all[OfflineQueuePolicy::RESELLER_HOOK]);
        $hooks->setValue(null, $all);
        parent::tearDown();
    }

    private function scenario(string $slug = 'bloom-coffee-demo', string $theme = 'brew'): ViewModelScenario
    {
        return new ViewModelScenario(Store::where('slug', $slug)->firstOrFail(), $theme);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    private function place(Store $store, array $lines, array $extra = [], array $headers = []): TestResponse
    {
        // P6-351: an unknown table is refused, so the posted table must exist.
        if (($extra['table'] ?? 'T4') !== null) {
            StoreTable::firstOrCreate(['store_id' => $store->id, 'key' => (string) ($extra['table'] ?? 'T4')], ['table_no' => 4, 'label' => 'T4', 'seats' => 4, 'status' => 'free', 'area_type' => 'table', 'branch_id' => null]);
        }

        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'lines' => $lines,
            'order_type' => 'dine_in',
            'table' => 'T4',
            'name' => 'Sam',
            'phone-number' => '+966500000001',
            'pay_via' => 'cash',
            'client_order_id' => (string) Str::uuid(),
        ], $extra), $headers);
    }

    private function setLevels(Store $store, bool $platform, bool $reseller, bool $owner): void
    {
        config(['settings.offline_queue_enabled' => $platform ? '1' : '0']);
        Hooks::on(OfflineQueuePolicy::RESELLER_HOOK, 'test-reseller', fn () => $reseller ? null : false);
        StoreSettings::set((int) $store->id, 'offline_queue_enabled', $owner);
    }

    private function orders(Store $store): int
    {
        return Order::where('store_id', $store->id)->count();
    }

    public function test_the_cart_the_js_quoted_is_the_order_that_is_placed(): void
    {
        $s = $this->scenario();
        $cart = $s->cart();
        $quote = $this->postJson("/{$s->store->slug}/quote", ['lines' => $cart, 'order_type' => 'dine_in'])->assertOk()->json('quote');

        $res = $this->place($s->store, $cart);
        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $s->store->id)->firstOrFail();
        $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, 'quoted total == placed total');
        $this->assertSame('on-table', $order->type);
        $this->assertSame(array_sum(array_column($cart, 'qty')), (int) $order->items->sum('quantity'));
    }

    public function test_every_endpoint_the_view_model_names_reaches_its_route_for_that_store(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $s = $this->scenario();
        $endpoints = $s->build('en')['endpoints'];

        foreach (['quote' => 'post', 'place' => 'post', 'coupon' => 'post', 'order_lookup' => 'post', 'slots' => 'get'] as $name => $method) {
            $response = $this->json($method, $endpoints[$name], []);
            $this->assertNotSame(404, $response->getStatusCode(), "endpoints.{$name} ({$endpoints[$name]}) must bind to this store (a slug where the route wants an id is a 404)");
        }
        $this->assertSame('/'.$s->store->id.'/send-order', $endpoints['place']);
    }

    public function test_every_item_with_choices_is_placed_at_its_quoted_total(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // one order per item: more than the 10 a minute a guest may place
        $s = $this->scenario();
        $home = $s->build('en');
        $checked = 0;
        foreach ($home['catalog']['items'] as $card) {
            if ($card['sold_out']) {
                continue;
            }
            $detail = $s->build('en', 'item', ['item' => $card['id']])['page']['item'] ?? null;
            if ($detail === null || $detail['option_groups'] === [] || isset($detail['variants'])) {
                continue;
            }
            $options = [];
            foreach ($detail['option_groups'] as $group) {
                $available = array_values(array_filter($group['options'], fn (array $o) => $o['available'] ?? true));
                foreach (array_slice($available, 0, max(1, (int) $group['min'])) as $o) {
                    $options[] = $o['id'];
                }
            }
            $cart = [['item' => $card['id'], 'qty' => 2, 'options' => $options]];
            $quote = $this->postJson("/{$s->store->slug}/quote", ['lines' => $cart, 'order_type' => 'dine_in'])->assertOk()->json('quote');
            $this->place($s->store, $cart)->assertJson(['success' => true]);
            $order = Order::where('store_id', $s->store->id)->latest('id')->firstOrFail();
            $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, "{$card['name']}: the order is placed at the quoted total (options + add-ons priced)");
            $checked++;
        }
        $this->assertGreaterThan(3, $checked, 'the demo menu has several items with choices');
    }

    public function test_idempotent_replay_same_client_id_twice_is_one_order(): void
    {
        $s = $this->scenario();
        $cart = $s->cart();
        $id = (string) Str::uuid();

        $this->place($s->store, $cart, ['client_order_id' => $id])->assertJson(['success' => true]);
        $this->place($s->store, $cart, ['client_order_id' => $id])->assertJson(['success' => true, 'duplicate' => true]);
        $this->assertSame(1, $this->orders($s->store));

        // the Idempotency-Key header is the same key
        $other = (string) Str::uuid();
        $this->place($s->store, $cart, ['client_order_id' => null], ['Idempotency-Key' => $other])->assertJson(['success' => true]);
        $this->place($s->store, $cart, ['client_order_id' => null], ['Idempotency-Key' => $other])->assertJson(['duplicate' => true]);
        $this->assertSame(2, $this->orders($s->store));
    }

    public function test_the_same_client_id_with_another_cart_is_a_409_never_a_second_order(): void
    {
        $s = $this->scenario();
        $cart = $s->cart();
        $id = (string) Str::uuid();

        $this->place($s->store, $cart, ['client_order_id' => $id])->assertJson(['success' => true]);
        $this->place($s->store, [$cart[0] + ['qty' => 5]], ['client_order_id' => $id])->assertStatus(409)->assertJson(['success' => false, 'conflict' => true]);
        $this->assertSame(1, $this->orders($s->store));
    }

    public function test_a_line_of_another_store_is_refused_whole(): void
    {
        $s = $this->scenario();
        $foreign = $this->scenario('fresh-mart-demo', 'basket')->cart();

        $this->place($s->store, [$foreign[0]])->assertJson(['success' => false]);
        $this->place($s->store, array_merge($s->cart(), [$foreign[0]]))->assertJson(['success' => false]);
        $this->assertSame(0, $this->orders($s->store));
        $this->assertSame(0, $this->orders($this->scenario('fresh-mart-demo', 'basket')->store));
    }

    public function test_a_malformed_client_id_is_refused_not_ignored(): void
    {
        $s = $this->scenario();
        $this->place($s->store, $s->cart(), ['client_order_id' => 'not-a-uuid'])->assertStatus(422);
        $this->assertSame(0, $this->orders($s->store));
    }

    /** @return iterable<string, array{0: bool, 1: bool, 2: bool}> */
    public static function levels(): iterable
    {
        foreach ([true, false] as $platform) {
            foreach ([true, false] as $reseller) {
                foreach ([true, false] as $owner) {
                    yield sprintf('platform %s, reseller %s, owner %s', $platform ? 'on' : 'off', $reseller ? 'on' : 'off', $owner ? 'on' : 'off') => [$platform, $reseller, $owner];
                }
            }
        }
    }

    #[DataProvider('levels')]
    public function test_kill_switch_matrix_any_level_off_means_off(bool $platform, bool $reseller, bool $owner): void
    {
        $s = $this->scenario();
        $this->setLevels($s->store, $platform, $reseller, $owner);
        $on = $platform && $reseller && $owner;

        $this->assertSame($on, app(OfflineQueuePolicy::class)->enabled($s->store));

        $queued = $this->place($s->store, $s->cart(), [], ['X-Juwri-Queued' => '1']);
        if ($on) {
            $queued->assertOk()->assertJson(['success' => true]);
            $this->assertSame(1, $this->orders($s->store));
        } else {
            $queued->assertStatus(403)->assertJson(['success' => false, 'code' => 'offline_queue_disabled']);
            $this->assertSame(0, $this->orders($s->store), 'a queued order never lands once any level is off');
        }

        // an ordinary online order is never touched by the queue switches
        $this->place($s->store, $s->cart())->assertOk()->assertJson(['success' => true]);
        $this->assertSame($on ? 2 : 1, $this->orders($s->store));
    }

    public function test_defaults_are_on_at_every_level(): void
    {
        $s = $this->scenario();
        $this->assertTrue(app(OfflineQueuePolicy::class)->enabled($s->store));
        $this->place($s->store, $s->cart(), [], ['X-Juwri-Queued' => '1'])->assertOk()->assertJson(['success' => true]);
    }

    public function test_a_retry_of_an_order_that_already_landed_still_answers_after_the_switch_is_turned_off(): void
    {
        $s = $this->scenario();
        $cart = $s->cart();
        $id = (string) Str::uuid();
        $this->place($s->store, $cart, ['client_order_id' => $id], ['X-Juwri-Queued' => '1'])->assertJson(['success' => true]);

        StoreSettings::set((int) $s->store->id, 'offline_queue_enabled', false);

        $this->place($s->store, $cart, ['client_order_id' => $id], ['X-Juwri-Queued' => '1'])->assertOk()->assertJson(['success' => true, 'duplicate' => true]);
        $this->assertSame(1, $this->orders($s->store));
    }

    public function test_only_dine_in_and_pay_on_collection_may_queue(): void
    {
        $s = $this->scenario();
        $cart = $s->cart();
        $queued = ['X-Juwri-Queued' => '1'];

        $this->place($s->store, $cart, ['order_type' => 'delivery', 'address' => '1 Main St'], $queued)
            ->assertStatus(422)->assertJson(['code' => 'offline_queue_type']);
        $this->place($s->store, $cart, ['pay_via' => 'pay_online'], $queued)
            ->assertStatus(422)->assertJson(['code' => 'offline_queue_payment']);
        $this->assertSame(0, $this->orders($s->store));

        $this->place($s->store, $cart, ['order_type' => 'takeaway', 'table' => null], $queued)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $this->orders($s->store));
    }

    public function test_a_queued_order_expires_at_the_store_set_limit(): void
    {
        $s = $this->scenario();
        StoreSettings::set((int) $s->store->id, 'offline_queue_expiry_min', 10);
        $queued = ['X-Juwri-Queued' => '1'];

        $this->place($s->store, $s->cart(), ['queued_at' => now()->subMinutes(5)->toIso8601String()], $queued)->assertOk()->assertJson(['success' => true]);
        $this->place($s->store, $s->cart(), ['queued_at' => now()->subMinutes(15)->toIso8601String()], $queued)
            ->assertStatus(422)->assertJson(['code' => 'offline_queue_expired']);
        $this->place($s->store, $s->cart(), ['queued_at' => 'garbage'], $queued)->assertStatus(422)->assertJson(['code' => 'offline_queue_expired']);
        $this->assertSame(1, $this->orders($s->store));

        // a clock that runs ahead counts as "now", never as expired
        $this->place($s->store, $s->cart(), ['queued_at' => now()->addMinutes(3)->toIso8601String()], $queued)->assertOk()->assertJson(['success' => true]);
    }

    public function test_the_default_expiry_is_thirty_minutes(): void
    {
        $s = $this->scenario();
        $this->assertSame(30, app(OfflineQueuePolicy::class)->expiryMinutes($s->store));
        $this->place($s->store, $s->cart(), ['queued_at' => now()->subMinutes(29)->toIso8601String()])->assertOk()->assertJson(['success' => true]);
        $this->place($s->store, $s->cart(), ['queued_at' => now()->subMinutes(40)->toIso8601String()])->assertStatus(422)->assertJson(['code' => 'offline_queue_expired']);
    }
}
