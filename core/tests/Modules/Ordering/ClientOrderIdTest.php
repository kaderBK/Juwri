<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\Agents\AgentToolsService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J27-A2: idempotent client orders. Every order-creating entry point accepts a
 * client-generated `client_order_id` (UUID; header Idempotency-Key or body field).
 *
 * Same key + same cart → the SAME order and the same answer (sequential and concurrent);
 * same key + another cart → 409, never a second order; malformed key → 422. The tests
 * that were characterisation first (step 1) carry a `before:` line with the old result.
 */
class ClientOrderIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1', 'storefront_api' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: Menu, 1: Menu} */
    private function items(Store $r): array
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return [
            Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]),
            Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Other', 'price' => 7, 'active' => 1]),
        ];
    }

    private function ownerToken(Store $r): string
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $r->user_id, 'name' => 't', 'token' => hash('sha256', $raw),
            'abilities' => ['read', 'write', 'orders:read', 'orders:write'],
        ]);

        return $raw;
    }

    private function staff(Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'C',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
    }

    private const K1 = '0190f3a2-7b1c-7def-8abc-0123456789ab'; // UUIDv7

    private const K2 = '0190f3a2-7b1c-7def-9abc-0123456789ac'; // UUIDv7

    private const V4 = '3b241101-e2bb-4255-8caf-4136c566a962'; // UUIDv4

    private static function storefrontItems(Menu $item, int $qty): string
    {
        return json_encode([['id' => $item->id, 'quantity' => $qty, 'variants' => 0, 'extras' => [], 'mods' => []]]);
    }

    /**
     * The concurrent twin: a competing request with the same key commits its order right after OUR replay check
     * read "no order yet" (the first SELECT by this key) and before our INSERT — so the INSERT meets the unique
     * index and the recovery path must hand back the twin's order.
     */
    private function twinInsertsFirst(int $storeId, string $key, ?string $hash): void
    {
        $fired = false;
        DB::listen(function ($query) use (&$fired, $storeId, $key, $hash) {
            if ($fired || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'client_uuid') || ! in_array($key, $query->bindings, true)) {
                return;
            }
            $fired = true;
            DB::table('orders')->insert([
                'store_id' => $storeId, 'client_uuid' => $key, 'client_order_hash' => $hash, 'type' => 'takeaway',
                'status' => 'pending', 'track_token' => Str::random(24), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function storefront(Store $r, Menu $item, int $qty, ?string $key)
    {
        return $this->post(route('store.submitOrder', $r), array_filter([
            'items' => self::storefrontItems($item, $qty),
            'name' => 'Guest', 'ordering-type' => 'takeaway', 'phone-number' => '+16505551234',
            'pay_via' => 'cash', 'total' => (string) ($qty * 10),
            'client_uuid' => $key,
        ], fn ($v) => $v !== null));
    }

    private function laundryStore(): array
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'laundry']);
        foreach (['allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30, 'scheduling_lead_minutes' => 30,
            'scheduling_prep_minutes' => 20, 'timezone' => 'UTC'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $shirt = Menu::create(['store_id' => $store->id, 'name' => 'Shirt', 'price' => 3.5, 'pricing_unit' => 'each', 'active' => 1]);
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        return [$store, $shirt];
    }

    private function laundryPlace(Store $store, array $extra = [])
    {
        return $this->post(route('laundry.book.place', ['slug' => $store->slug]), $extra + [
            'type' => 'pickup_slot', 'customer_name' => 'Sam', 'address' => '221B Baker Street',
            'scheduled_at' => Carbon::now('UTC')->addHours(2)->startOfHour()->format('Y-m-d H:i'),
        ]);
    }

    // ------------------------------------------------------------ helper

    public function test_the_key_must_be_a_uuid_v7_or_v4_and_is_normalised(): void
    {
        $this->assertSame(self::K1, ClientOrderId::validated(strtoupper(self::K1)));
        $this->assertSame(self::V4, ClientOrderId::validated(' '.self::V4.' '));
        $this->assertSame('pos-1727000000000-k3j9x2ab', ClientOrderId::validated('pos-1727000000000-k3j9x2ab', acceptLegacyPos: true));

        foreach (['k-1', 'not-a-uuid', 'pos-1727000000000-k3j9x2ab', '0190f3a2-7b1c-0def-8abc-0123456789ab', str_repeat('a', 36), ''] as $bad) {
            try {
                ClientOrderId::validated($bad);
                $this->fail("'$bad' must be refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('client_order_id', $e->errors());
            }
        }
    }

    public function test_the_fingerprint_ignores_key_order_number_spelling_and_empty_fields(): void
    {
        $a = ClientOrderId::fingerprint(['type' => 'takeaway', 'items' => [['item_id' => 5, 'quantity' => 2, 'extras' => []]]]);
        $b = ClientOrderId::fingerprint(['items' => [['quantity' => '2.0', 'item_id' => '5', 'note' => null]], 'type' => 'takeaway']);
        $c = ClientOrderId::fingerprint(['type' => 'takeaway', 'items' => [['item_id' => 5, 'quantity' => 3]]]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertTrue(Schema::hasColumn('orders', 'client_order_hash'));
    }

    public function test_the_new_messages_are_translated_in_every_locale(): void
    {
        foreach ([
            'This order was already placed with a different cart. Start a new order.',
            'The key must be a UUID (v7 recommended, v4 accepted); any other value is rejected with 422. Reusing a key with a different cart returns 409.',
        ] as $english) {
            $key = 'lang.'.Str::slug($english, '_');
            foreach (['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
                $this->assertTrue(trans()->hasForLocale($key, $locale), "$key missing in $locale");
                $text = trans($key, [], $locale);
                $locale === 'en' ? $this->assertSame($english, $text) : $this->assertNotSame($english, $text, "$locale is English");
            }
        }
    }

    // ------------------------------------------------------------ service

    public function test_service_same_key_different_cart_is_a_409(): void
    {
        $r = $this->storeFixture();
        [$a, $b] = $this->items($r);

        $first = OrderApiService::create((int) $r->user_id, $r->id, ['type' => 'takeaway', 'client_uuid' => 'k-1',
            'items' => [['item_id' => $a->id, 'quantity' => 1]]]);

        // before J27-A2: the second call silently returned $first (the guest's new cart was dropped).
        try {
            OrderApiService::create((int) $r->user_id, $r->id, ['type' => 'takeaway', 'client_uuid' => 'k-1',
                'items' => [['item_id' => $b->id, 'quantity' => 3]]]);
            $this->fail('a different cart under the same key must be refused');
        } catch (ClientOrderConflict $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame($first->id, $e->orderId);
        }
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }

    public function test_service_sequential_resend_returns_the_same_order(): void
    {
        $r = $this->storeFixture();
        [$a] = $this->items($r);
        $data = ['type' => 'takeaway', 'client_uuid' => self::K1, 'items' => [['item_id' => $a->id, 'quantity' => 2]]];

        [$first, $replayedFirst] = OrderApiService::createOrReplay((int) $r->user_id, $r->id, $data);
        // The same cart spelled differently by a retrying client is still the same cart.
        [$second, $replayedSecond] = OrderApiService::createOrReplay((int) $r->user_id, $r->id,
            ['type' => 'takeaway', 'client_uuid' => self::K1, 'items' => [['quantity' => '2', 'item_id' => (string) $a->id]]]);

        $this->assertFalse($replayedFirst);
        $this->assertTrue($replayedSecond);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
        $this->assertSame(64, strlen((string) $first->fresh()->client_order_hash));
    }

    public function test_service_concurrent_twin_yields_one_order(): void
    {
        $r = $this->storeFixture();
        [$a] = $this->items($r);
        $items = [['item_id' => $a->id, 'quantity' => 1]];
        $this->twinInsertsFirst($r->id, self::K1, ClientOrderId::fingerprint(['type' => 'takeaway', 'items' => $items]));

        [$order, $replayed] = OrderApiService::createOrReplay((int) $r->user_id, $r->id,
            ['type' => 'takeaway', 'client_uuid' => self::K1, 'items' => $items]);

        $this->assertTrue($replayed, 'the loser of the race hands back the winner');
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
        $this->assertSame((int) Order::where('store_id', $r->id)->value('id'), (int) $order->id);
    }

    public function test_service_concurrent_twin_with_another_cart_is_a_409(): void
    {
        $r = $this->storeFixture();
        [$a] = $this->items($r);
        $this->twinInsertsFirst($r->id, self::K1, str_repeat('f', 64));

        $this->expectException(ClientOrderConflict::class);
        try {
            OrderApiService::create((int) $r->user_id, $r->id,
                ['type' => 'takeaway', 'client_uuid' => self::K1, 'items' => [['item_id' => $a->id, 'quantity' => 1]]]);
        } finally {
            $this->assertSame(1, Order::where('store_id', $r->id)->count());
        }
    }

    public function test_an_old_row_without_a_fingerprint_still_replays(): void
    {
        $r = $this->storeFixture();
        [$a, $b] = $this->items($r);
        $first = OrderApiService::create((int) $r->user_id, $r->id, ['type' => 'takeaway', 'client_uuid' => self::K1,
            'items' => [['item_id' => $a->id, 'quantity' => 1]]]);
        Order::whereKey($first->id)->update(['client_order_hash' => null]); // created before J27-A2

        $again = OrderApiService::create((int) $r->user_id, $r->id, ['type' => 'takeaway', 'client_uuid' => self::K1,
            'items' => [['item_id' => $b->id, 'quantity' => 1]]]);

        $this->assertSame($first->id, $again->id);
    }

    // ------------------------------------------------------------ owner API v1

    public function test_owner_api_refuses_a_key_that_is_not_a_uuid(): void
    {
        $r = $this->storeFixture();
        [$a] = $this->items($r);
        $headers = ['Authorization' => 'Bearer '.$this->ownerToken($r), 'Idempotency-Key' => 'not-a-uuid'];

        // before J27-A2: 201 — any string was accepted as a key.
        $this->postJson("/api/v1/stores/{$r->id}/orders", ['type' => 'takeaway',
            'items' => [['item_id' => $a->id, 'quantity' => 1]]], $headers)->assertStatus(422)
            // the message names what the client sent and says what is expected
            ->assertSee('The Idempotency-Key field must be a valid UUID.', false);
        $this->postJson("/api/v1/stores/{$r->id}/orders", ['type' => 'takeaway', 'client_order_id' => 'not-a-uuid',
            'items' => [['item_id' => $a->id, 'quantity' => 1]]], ['Authorization' => $headers['Authorization']])
            ->assertStatus(422)->assertSee('The client order id field must be a valid UUID.', false);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    public function test_owner_api_resend_returns_the_same_answer_and_another_cart_is_a_409(): void
    {
        $r = $this->storeFixture();
        [$a, $b] = $this->items($r);
        $headers = ['Authorization' => 'Bearer '.$this->ownerToken($r), 'Idempotency-Key' => self::K1];
        $body = ['type' => 'takeaway', 'items' => [['item_id' => $a->id, 'quantity' => 1]]];

        $one = $this->postJson("/api/v1/stores/{$r->id}/orders", $body, $headers)->assertStatus(201);
        $two = $this->postJson("/api/v1/stores/{$r->id}/orders", $body, $headers)->assertStatus(201);
        $this->assertSame($one->json(), $two->json(), 'a resend gets the same answer');

        // before J27-A2: 201 with the first order's id.
        $this->postJson("/api/v1/stores/{$r->id}/orders", ['type' => 'takeaway',
            'items' => [['item_id' => $b->id, 'quantity' => 2]]], $headers)
            ->assertStatus(409)->assertJsonPath('code', 'conflict');

        // v4 in the body field is accepted too.
        $this->postJson("/api/v1/stores/{$r->id}/orders", $body + ['client_order_id' => self::V4],
            ['Authorization' => $headers['Authorization']])->assertStatus(201);

        $this->assertSame(2, Order::where('store_id', $r->id)->count());
    }

    // ------------------------------------------------------------ storefront (QR / kiosk / web)

    /** @return array<string, array{0: string}> */
    public static function pipelines(): array
    {
        return ['legacy' => ['legacy'], 'unified' => ['unified']];
    }

    #[DataProvider('pipelines')]
    public function test_storefront_same_key_different_cart_is_a_409(string $pipeline): void
    {
        config(['settings.storefront_order_pipeline' => $pipeline]);
        $r = $this->storeFixture();
        [$a] = $this->items($r);

        $first = $this->storefront($r, $a, 1, self::K1)->assertJson(['success' => true]);
        $this->storefront($r, $a, 1, self::K1)->assertJson(['success' => true, 'duplicate' => true,
            'track_url' => $first->json('track_url')]);
        // before J27-A2: {success: true, duplicate: true} — the 4-item cart was silently dropped.
        $this->storefront($r, $a, 4, self::K1)->assertStatus(409)
            ->assertJson(['success' => false, 'conflict' => true,
                'message' => 'This order was already placed with a different cart. Start a new order.']);

        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }

    #[DataProvider('pipelines')]
    public function test_storefront_concurrent_twin_yields_one_order(string $pipeline): void
    {
        config(['settings.storefront_order_pipeline' => $pipeline]);
        $r = $this->storeFixture();
        [$a] = $this->items($r);
        $hash = ClientOrderId::fingerprint(['type' => 'takeaway', 'items' => json_decode(self::storefrontItems($a, 1), true), 'combos' => []]);
        $this->twinInsertsFirst($r->id, self::K1, $hash);

        $this->storefront($r, $a, 1, self::K1)->assertOk()->assertJson(['success' => true, 'duplicate' => true]);

        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }

    public function test_storefront_refuses_a_key_that_is_not_a_uuid(): void
    {
        $r = $this->storeFixture();
        [$a] = $this->items($r);

        // before J27-A2: success — 'plain-token' was accepted.
        $this->storefront($r, $a, 1, 'plain-token')->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    // ------------------------------------------------------------ POS

    public function test_pos_same_key_different_cart_is_a_409(): void
    {
        $r = $this->storeFixture();
        [$a, $b] = $this->items($r);
        $staff = $this->staff($r);

        $this->actingAs($staff, 'staff')->postJson(route('pos.orders.store'), ['type' => 'takeaway',
            'client_order_id' => self::K1, 'items' => [['item_id' => $a->id, 'quantity' => 1]]])->assertStatus(201);
        // before J27-A2: 201 with the first order's id.
        $this->actingAs($staff, 'staff')->postJson(route('pos.orders.store'), ['type' => 'takeaway',
            'client_order_id' => self::K1, 'items' => [['item_id' => $b->id, 'quantity' => 1]]])->assertStatus(409);

        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }

    public function test_pos_offline_replay_returns_the_same_order_and_answer(): void
    {
        $r = $this->storeFixture();
        [$a] = $this->items($r);
        $staff = $this->staff($r);

        // A sale queued offline by the pre-J27 register (old key shape) and one by the new register (UUIDv7):
        // the queue replays each one several times on reconnect.
        foreach (['pos-1727000000000-k3j9x2ab', self::K2] as $key) {
            $sale = ['type' => 'takeaway', 'held' => false, 'client_uuid' => $key, 'items' => [['item_id' => $a->id, 'quantity' => 2]]];
            $answers = [];
            foreach (range(1, 3) as $i) {
                $answers[] = $this->actingAs($staff, 'staff')->postJson(route('pos.orders.store'), $sale)->assertStatus(201)->json();
            }
            $this->assertSame($answers[0], $answers[1]);
            $this->assertSame($answers[0], $answers[2]);
        }

        $this->assertSame(2, Order::where('store_id', $r->id)->count());
        $this->actingAs($staff, 'staff')->postJson(route('pos.orders.store'), ['type' => 'takeaway', 'client_uuid' => 'queued-1',
            'items' => [['item_id' => $a->id, 'quantity' => 1]]])->assertStatus(422);
    }

    // ------------------------------------------------------------ goods / service checkout

    public function test_goods_resend_returns_the_same_booking(): void
    {
        [$store, $shirt] = $this->laundryStore();
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $shirt->id]), ['quantity' => 2]);

        $first = $this->laundryPlace($store, ['client_order_id' => self::K1])->assertRedirect();
        // before J27-A2: the resend failed with "Your cart is empty." (cart already converted).
        $second = $this->laundryPlace($store, ['client_order_id' => self::K1])->assertRedirect();

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
        $this->assertSame(self::K1, Order::where('store_id', $store->id)->value('client_uuid'));
    }

    public function test_goods_resend_with_another_cart_is_a_409(): void
    {
        [$store, $shirt] = $this->laundryStore();
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $shirt->id]), ['quantity' => 2]);
        $this->laundryPlace($store, ['client_order_id' => self::K1])->assertRedirect();

        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $shirt->id]), ['quantity' => 5]);
        $this->laundryPlace($store, ['client_order_id' => self::K1])
            ->assertSessionHasErrors(['cart' => 'This order was already placed with a different cart. Start a new order.']);
        $this->withHeaders(['Accept' => 'application/json'])
            ->laundryPlace($store, ['client_order_id' => self::K1])->assertStatus(409)->assertJson(['conflict' => true]);

        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_goods_concurrent_twin_yields_one_order(): void
    {
        [$store, $shirt] = $this->laundryStore();
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $shirt->id]), ['quantity' => 2]);
        $this->twinInsertsFirst($store->id, self::K1, null);

        $this->laundryPlace($store, ['client_order_id' => self::K1])->assertRedirect();

        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_goods_refuses_a_key_that_is_not_a_uuid(): void
    {
        [$store, $shirt] = $this->laundryStore();
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $shirt->id]), ['quantity' => 2]);

        $this->laundryPlace($store, ['client_order_id' => 'nope'])->assertSessionHasErrors('client_order_id');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    // ------------------------------------------------------------ agents / storefront API

    public function test_agent_tools_report_a_conflict_instead_of_a_second_order(): void
    {
        $r = $this->storeFixture();
        [$a, $b] = $this->items($r);

        // The Storefront API v1 order write (createOrder) runs through here and answers 409 on `conflict`.
        $one = AgentToolsService::createOrder($r, [['item_id' => $a->id, 'quantity' => 1]], ['type' => 'takeaway', 'client_uuid' => self::K1], null, 'storefront_api');
        $two = AgentToolsService::createOrder($r, [['item_id' => $b->id, 'quantity' => 1]], ['type' => 'takeaway', 'client_uuid' => self::K1], null, 'storefront_api');

        $this->assertTrue($one['ok']);
        $this->assertFalse($two['ok']);
        $this->assertTrue($two['conflict']);
        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }
}
