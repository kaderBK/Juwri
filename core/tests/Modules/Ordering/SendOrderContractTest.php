<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Mails\StoreOrderMail;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuItemEvent;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Tables\Models\WaiterCall;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09k characterisation of the storefront checkout (User\StoreController::
 * submitOrder, legacy pipeline) and the call-the-waiter endpoint: validation
 * envelope, the three order-type branches (order columns, customer-details
 * text, order-type label), the item / variant / extra loop (order_items,
 * order_item_extras, the owner's order text and the WhatsApp detail lines),
 * the owner's new-order mail, the WhatsApp template tags and the pay-online
 * Transaction.
 */
class SendOrderContractTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'ID={ORDER_ID}|D={ORDER_DETAILS}|C={CUSTOMER_DETAILS}|T={ORDER_TYPE}|P={ORDER_TOTAL}';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        unset($_POST['message']);
    }

    protected function tearDown(): void
    {
        unset($_POST['message']);
        parent::tearDown();
    }

    private function store(array $options = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Chez Test', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '+44 20 7946 0000',
        ]);
        foreach ($options as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }

        return $store;
    }

    /** Burger 10 with a "Large" variant at 12 and a Cheese extra at 1.50; Fries 3. */
    private function menu(Store $store): array
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $burger = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10.00, 'active' => 1]);
        $fries = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Fries', 'price' => 3.00, 'active' => 1]);
        $size = MenuVariantOption::create([
            'menu_id' => $burger->id, 'title' => 'Size', 'options' => ['Small', 'Large'], 'position' => 0, 'active' => 1,
        ]);
        $large = MenuVariant::create([
            'menu_id' => $burger->id, 'price' => 12.00, 'options' => [$size->id => 1], 'active' => 1, 'position' => 0,
        ]);
        $cheese = MenuExtra::create(['menu_id' => $burger->id, 'title' => 'Cheese', 'price' => 1.50, 'position' => 0, 'active' => 1]);
        $foreign = MenuExtra::create(['menu_id' => $fries->id, 'title' => 'Foreign', 'price' => 9.00, 'position' => 0, 'active' => 1]);

        return compact('burger', 'fries', 'large', 'cheese', 'foreign');
    }

    private function items(array $m): string
    {
        return json_encode([
            ['id' => $m['burger']->id, 'quantity' => 2, 'variants' => $m['large']->id,
                'extras' => [['id' => $m['cheese']->id], ['id' => $m['foreign']->id]], 'mods' => []],
            ['id' => $m['fries']->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
        ]);
    }

    private function send(Store $store, array $fields): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $store), $fields);
    }

    private function whatsappText(TestResponse $res): string
    {
        $url = (string) $res->json('whatsapp_url');
        $this->assertStringStartsWith('https://api.whatsapp.com/send?phone=442079460000&text=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return (string) $q['text'];
    }

    private function mailData(): array
    {
        $found = null;
        Mail::assertQueued(StoreOrderMail::class, function (StoreOrderMail $mail) use (&$found) {
            $found = (fn () => $this->order)->call($mail);

            return true;
        });

        return $found;
    }

    private const WA_ON = ['whatsapp_ordering_enable' => '1', 'whatsapp_message' => self::TEMPLATE];

    public function test_validation_failures_answer_with_every_message_joined_by_br(): void
    {
        $store = $this->store();

        $res = $this->send($store, []);
        $res->assertOk()->assertExactJson(['success' => false, 'message' => implode('<br>', [
            'The items field is required when combos is not present.',
            'The name field is required.',
            'The ordering-type field is required.',
            'The phone-number field is required unless ordering-type is in on-table, room_service.',
            'The pay via field is required.',
        ])]);

        $res = $this->send($store, ['items' => '[]', 'name' => 'G', 'ordering-type' => 'on-table', 'pay_via' => 'cash']);
        $res->assertExactJson(['success' => false, 'message' => 'The table field is required when ordering-type is on-table.']);

        $res = $this->send($store, ['items' => '[]', 'name' => 'G', 'ordering-type' => 'delivery', 'phone-number' => '1', 'pay_via' => 'cash']);
        $res->assertExactJson(['success' => false, 'message' => 'The address field is required when ordering-type is delivery.']);

        $res = $this->send($store, ['items' => '[]', 'name' => 'G', 'ordering-type' => 'drive-in', 'phone-number' => '1', 'pay_via' => 'cash']);
        $res->assertExactJson(['success' => false, 'message' => 'The selected ordering-type is invalid.']);

        $this->assertSame(0, Order::count());
    }

    public function test_takeaway_order_lines_extras_texts_mail_and_whatsapp(): void
    {
        $store = $this->store(self::WA_ON);
        $m = $this->menu($store);
        $_POST['message'] = 'Ring bell';

        $res = $this->send($store, [
            'items' => $this->items($m), 'name' => 'Guest', 'ordering-type' => 'takeaway',
            'phone-number' => '+16505551234', 'message' => 'Ring bell', 'pay_via' => 'cash',
        ]);
        $res->assertOk()->assertJson(['success' => true, 'message' => '']);

        $order = Order::where('store_id', $store->id)->sole();
        $this->assertSame('takeaway', $order->type);
        $this->assertSame('Guest', $order->customer_name);
        $this->assertSame('+16505551234', $order->phone_number);
        $this->assertNull($order->address);
        $this->assertNull($order->table_number);
        $this->assertSame('Ring bell', $order->message);
        $this->assertNotSame('unpaid', $order->status);
        $this->assertSame($order->id, $res->json('order_id'));

        $lines = OrderItem::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([$m['burger']->id, 2, $m['large']->id], [(int) $lines[0]->item_id, (int) $lines[0]->quantity, (int) $lines[0]->variation]);
        $this->assertSame([$m['fries']->id, 1, 0], [(int) $lines[1]->item_id, (int) $lines[1]->quantity, (int) $lines[1]->variation]);
        $extras = OrderItemExtra::whereIn('order_item_id', $lines->pluck('id'))->get();
        $this->assertCount(1, $extras, 'an extra the item does not offer is dropped');
        $this->assertSame([(int) $lines[0]->id, $m['cheese']->id, 1.5], [(int) $extras[0]->order_item_id, (int) $extras[0]->extra_id, (float) $extras[0]->price]);

        $orderText = 'Burger (Large) &times; 2<br>Cheese<br><br>Fries<br><br>';
        $this->assertSame([
            'store_name' => 'Chez Test',
            'customer_name' => 'Guest',
            'table_number' => null,
            'phone_number' => '+16505551234',
            'address' => null,
            'order_type' => 'Takeaway',
            'order' => $orderText,
            'message' => 'Ring bell',
        ], $this->mailData());

        $this->assertSame(
            'ID='.$order->id
            ."|D=▪️Burger (Large) X 2\n\t▫️Cheese\n▪️Fries X 1\n"
            ."|C=Guest\n☎️ +16505551234\n📝 Ring bell\n"
            .'|T=Takeaway'
            .'|P='.price_code_format(30.0),
            $this->whatsappText($res)
        );
    }

    public function test_delivery_order_address_pin_charge_and_customer_details(): void
    {
        $store = $this->store(self::WA_ON + ['restaurant_delivery_charge' => '4']);
        $m = $this->menu($store);

        $res = $this->send($store, [
            'items' => json_encode([['id' => $m['fries']->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Guest', 'ordering-type' => 'delivery', 'phone-number' => '555', 'address' => '9 Road',
            'address_lat' => '51.5', 'address_lng' => '-0.12', 'pay_via' => 'cash',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->sole();
        $this->assertSame(['delivery', '555', '9 Road'], [$order->type, $order->phone_number, $order->address]);
        $this->assertEqualsWithDelta(51.5, (float) $order->delivery_lat, 0.0001);

        $mail = $this->mailData();
        $this->assertSame(['Delivery', '555', '9 Road', null], [$mail['order_type'], $mail['phone_number'], $mail['address'], $mail['message']]);

        $this->assertSame(
            'ID='.$order->id
            ."|D=▪️Fries X 1\n"
            ."|C=Guest\n☎️ 555\n📌 9 Road\n📌 https://www.google.com/maps?q=51.5%2C-0.12"
            .'|T=Delivery'
            .'|P='.price_code_format(7.0),
            $this->whatsappText($res)
        );
    }

    public function test_on_table_order_table_number_and_label(): void
    {
        $store = $this->store(self::WA_ON + ['store_submit_order_notification' => '0']);
        $m = $this->menu($store);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 7, 'key' => Str::random(10)]);

        $res = $this->send($store, [
            'items' => json_encode([['id' => $m['fries']->id, 'quantity' => 3, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Guest', 'ordering-type' => 'on-table', 'table' => '7', 'pay_via' => 'cash',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->sole();
        $this->assertSame(['on-table', 7, null], [$order->type, (int) $order->table_number, $order->phone_number]);
        Mail::assertNotQueued(StoreOrderMail::class);

        $this->assertSame(
            'ID='.$order->id
            ."|D=▪️Fries X 3\n"
            ."|C=Guest\n#️⃣ 7"
            .'|T='.___('On table')
            .'|P='.price_code_format(9.0),
            $this->whatsappText($res)
        );
    }

    public function test_pay_online_creates_the_order_transaction_and_answers_its_payment_url(): void
    {
        $store = $this->store(self::WA_ON + ['currency_code' => 'EUR']);
        $m = $this->menu($store);

        $res = $this->send($store, [
            'items' => $this->items($m), 'name' => 'Guest', 'ordering-type' => 'takeaway',
            'phone-number' => '777', 'pay_via' => 'pay_online',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->sole();
        $this->assertSame('unpaid', $order->status);
        $tx = Transaction::sole();
        $this->assertSame(route('payment.index', $tx->id), $res->json('message'));
        $this->assertSame('Chez Test (#'.$order->id.')', $tx->product_name);
        $this->assertSame('Chez Test (#'.$order->id.')', $tx->transaction_description);
        $this->assertSame([$order->id, $store->id], [(int) $tx->product_id, (int) $tx->store_id]);
        $this->assertNull($tx->user_id, 'an order payment carries the store in store_id, never in user_id');
        $this->assertSame(['order', 'EUR', '127.0.0.1'], [$tx->transaction_method, $tx->currency_code, $tx->transaction_ip]);
        $due = (float) $order->fresh()->amount_due;
        $this->assertEqualsWithDelta($due, (float) $tx->amount, 0.001);
        $this->assertEqualsWithDelta($due, (float) $tx->base_amount, 0.001);
        $details = json_decode(json_encode($tx->details), true);
        $this->assertSame([
            'whatsapp_url' => $res->json('whatsapp_url'),
            'customer_name' => 'Guest',
            'phone' => '777',
        ], $details);
    }

    public function test_cash_order_creates_no_transaction_and_disabled_whatsapp_gives_empty_url(): void
    {
        $store = $this->store(['whatsapp_ordering_enable' => '0']);
        $m = $this->menu($store);

        $res = $this->send($store, [
            'items' => $this->items($m), 'name' => 'Guest', 'ordering-type' => 'takeaway',
            'phone-number' => '777', 'pay_via' => 'cash',
        ]);
        $res->assertOk()->assertJson(['success' => true, 'message' => '', 'whatsapp_url' => '']);
        $this->assertSame(0, Transaction::count());
        $this->assertArrayNotHasKey('whatsapp_handoff', $res->json());
    }

    public function test_call_the_waiter_validates_and_records_the_call(): void
    {
        $store = $this->store();
        $url = route('store.callTheWaiter', $store);

        $this->postJson($url, [])->assertOk()
            ->assertExactJson(['success' => false, 'message' => 'The table field is required.']);
        $this->postJson($url, ['table' => 'x', 'type' => 'dance'])->assertOk()
            ->assertExactJson(['success' => false, 'message' => 'The table field must be an integer.<br>The selected type is invalid.']);
        $this->assertSame(0, WaiterCall::count());

        $this->postJson($url, ['table' => '4'])->assertOk()
            ->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);
        $this->postJson($url, ['table' => 5, 'type' => 'request_bill'])->assertOk()
            ->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);

        $calls = WaiterCall::where('store_id', $store->id)->orderBy('id')->get();
        $this->assertSame([[4, 'call_waiter'], [5, 'request_bill']], $calls->map(fn ($c) => [(int) $c->table_no, $c->type])->all());
    }

    public function test_apply_coupon_validates_prices_and_labels_an_owner_coupon(): void
    {
        $store = $this->store();
        $url = route('store.applyCoupon', $store);

        $this->postJson($url, [])->assertOk()->assertExactJson(['success' => false, 'message' => implode('<br>', [
            'The code field is required.',
            'The subtotal field is required.',
        ])]);
        $this->postJson($url, ['code' => 'X', 'subtotal' => -1])->assertOk()
            ->assertExactJson(['success' => false, 'message' => 'The subtotal field must be at least 0.']);

        $unknown = $this->postJson($url, ['code' => 'NOPE', 'subtotal' => 10])->assertOk();
        $this->assertFalse($unknown->json('success'));
        $this->assertIsString($unknown->json('message'));

        Coupon::create([
            'code' => 'HALF', 'percentage' => 50, 'limit' => 5, 'used' => 0,
            'expiry_at' => now()->addDays(7), 'coupon_type' => 'percentage', 'user_id' => $store->user_id,
        ]);
        // the route binds by id only: a slug is a 404 before the action runs
        $this->postJson(route('store.applyCoupon', $store->slug), ['code' => 'HALF', 'subtotal' => 18])->assertNotFound();
        $ok = $this->postJson($url, ['code' => 'HALF', 'subtotal' => 18])->assertOk();
        $ok->assertJson(['success' => true, 'label' => 'HALF', 'coupon_type' => 'percentage', 'percentage' => 50]);
        $this->assertEqualsWithDelta(9.0, (float) $ok->json('discount'), 0.001);
        $this->assertSame(['coupon_type', 'discount', 'label', 'message', 'percentage', 'success'], collect($ok->json())->keys()->sort()->values()->all());
    }

    public function test_record_item_event_validates_scopes_to_the_store_and_records(): void
    {
        $store = $this->store();
        $m = $this->menu($store);
        $other = $this->store();
        $foreign = $this->menu($other);

        $this->postJson(route('store.recordItemEvent', $store->id), ['type' => 'view'])
            ->assertStatus(422)->assertExactJson(['success' => false, 'message' => 'The menu id field is required.']);
        $this->postJson(route('store.recordItemEvent', $store->id), ['menu_id' => $m['fries']->id, 'type' => 'buy'])
            ->assertStatus(422)->assertExactJson(['success' => false, 'message' => 'The selected type is invalid.']);
        $this->postJson(route('store.recordItemEvent', $store->id), ['menu_id' => $foreign['fries']->id, 'type' => 'view'])
            ->assertStatus(404)->assertExactJson(['success' => false, 'message' => 'Menu item not found or does not belong to this restaurant.']);
        $this->postJson(route('store.recordItemEvent', 'no-such-store-'.Str::random(5)), ['menu_id' => 1, 'type' => 'view'])
            ->assertNotFound();

        $this->postJson(route('store.recordItemEvent', $store->slug), ['menu_id' => $m['fries']->id, 'type' => 'add_to_cart'])
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Event recorded successfully.']);
        $this->assertSame(
            [[$m['fries']->id, $store->id, 'add_to_cart']],
            MenuItemEvent::all()->map(fn ($e) => [(int) $e->menu_id, (int) $e->store_id, $e->type])->all()
        );
    }
}
