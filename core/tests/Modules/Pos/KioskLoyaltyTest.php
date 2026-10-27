<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Pos\Services\Kiosk\KioskLoyalty;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Item 26, kiosk ↔ loyalty: opt-in, proof before any data (phone code in the kiosk's own OTP scope, or a single-use pass from
 * the customer's own phone), favourites through the Menu recommendation engine, a reorder priced from today's menu, the
 * customer forgotten with the visit, and one store's kiosk never reaching another store's customers or orders.
 */
class KioskLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private object $otp;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_kiosk' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->otp = new class implements SmsOtp
        {
            /** @var list<array{0: string, 1: string}> */
            public array $sent = [];

            public function request(string $phone, OtpScope $scope): array
            {
                $this->sent[] = [$phone, $scope->key];

                return [true, 'sent'];
            }

            public function verify(string $phone, string $code, OtpScope $scope): bool
            {
                return $code === '123456' && in_array([$phone, $scope->key], $this->sent, true);
            }
        };
        $this->app->instance(SmsOtp::class, $this->otp);
    }

    /** @return array{store: Store, customer: Customer, kabsa: Menu, tea: Menu, juice: Menu, order: Order} */
    private function make(bool $loyalty = true): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'])]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['kiosk_enabled' => '1', 'kiosk_exit_pin' => '4321', 'kiosk_idle_seconds' => '45', 'currency_code' => 'SAR', 'kiosk_loyalty' => $loyalty ? '1' : '0'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C', 'position' => 1]);
        $mk = fn (string $n, float $p) => Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $n, 'price' => $p, 'active' => '1', 'position' => 1]);
        $kabsa = $mk('Kabsa', 30);
        $tea = $mk('Tea', 5);
        $juice = $mk('Juice', 8);
        $customer = Customer::query()->create(['store_id' => $store->id, 'name' => 'Sara Ahmed', 'phone' => '+966500000001']);
        LoyaltyAccount::query()->create(['store_id' => $store->id, 'customer_id' => $customer->id, 'balance' => 120]);
        $order = $this->pastOrder($store, $customer, [[$tea, 3], [$kabsa, 1], [$juice, 1]]);
        $this->pastOrder($store, $customer, [[$tea, 2]]);

        return compact('store', 'customer', 'kabsa', 'tea', 'juice', 'order');
    }

    /** @param  list<array{0: Menu, 1: int, 2?: array<int, mixed>}>  $lines */
    private function pastOrder(Store $store, Customer $customer, array $lines): Order
    {
        $order = Order::query()->create(['store_id' => $store->id, 'customer_id' => $customer->id, 'type' => 'takeaway', 'source' => 'kiosk', 'status' => 'completed']);
        foreach ($lines as $l) {
            OrderItem::query()->create(['order_id' => $order->id, 'item_id' => $l[0]->id, 'quantity' => $l[1], 'unit_price' => 1.0, 'options' => $l[2] ?? null]);
        }

        return $order;
    }

    /** A kiosk session of this store (the kiosk route marks it). */
    private function atKiosk(Store $store): static
    {
        $this->get(route('publicView.kiosk', $store->slug))->assertOk();

        return $this;
    }

    private function recognise(array $t): void
    {
        $this->postJson(route('publicView.kiosk.loyalty.code', $t['store']->slug), ['phone' => '+966500000001', 'opt_in' => '1'])->assertOk();
        $this->postJson(route('publicView.kiosk.loyalty.verify', $t['store']->slug), ['phone' => '+966500000001', 'code' => '123456'])->assertOk();
    }

    public function test_only_a_live_kiosk_session_with_the_switch_on_reaches_it(): void
    {
        $t = $this->make(false);
        $this->postJson(route('publicView.kiosk.loyalty.code', $t['store']->slug), ['phone' => '+966500000001', 'opt_in' => '1'])->assertNotFound(); // not a kiosk
        $this->atKiosk($t['store'])->postJson(route('publicView.kiosk.loyalty.code', $t['store']->slug), ['phone' => '+966500000001', 'opt_in' => '1'])->assertNotFound(); // switch off
        $this->assertSame([], $this->otp->sent);
    }

    public function test_opt_in_first_then_the_same_answer_for_a_known_and_an_unknown_phone_and_no_data_before_the_code(): void
    {
        $t = $this->make();
        $this->atKiosk($t['store']);
        $this->postJson(route('publicView.kiosk.loyalty.code', $t['store']->slug), ['phone' => '+966500000001'])->assertStatus(422);
        $known = $this->postJson(route('publicView.kiosk.loyalty.code', $t['store']->slug), ['phone' => '+966500000001', 'opt_in' => '1'])->assertOk()->json();
        $unknown = $this->postJson(route('publicView.kiosk.loyalty.code', $t['store']->slug), ['phone' => '+966599999999', 'opt_in' => '1'])->assertOk()->json();
        $this->assertSame($known, $unknown, 'nothing tells whether a phone is a customer here');
        $this->assertSame([['+966500000001', 'kiosk:'.$t['store']->id]], $this->otp->sent, 'a code only to a customer, in the kiosk scope (never a storefront login code)');
        $this->getJson(route('publicView.kiosk.loyalty.me', $t['store']->slug))->assertNotFound()->assertExactJson(['recognised' => false]);
        $this->postJson(route('publicView.kiosk.loyalty.verify', $t['store']->slug), ['phone' => '+966500000001', 'code' => '000000'])->assertStatus(422);
        $this->getJson(route('publicView.kiosk.loyalty.me', $t['store']->slug))->assertNotFound();
    }

    public function test_once_confirmed_first_name_points_favourites_from_the_engine_and_recent_orders(): void
    {
        $t = $this->make();
        $this->atKiosk($t['store']);
        $this->recognise($t);
        $me = $this->getJson(route('publicView.kiosk.loyalty.me', $t['store']->slug))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame(['Sara', 120], [$me['name'], $me['points']]);
        $this->assertSame([(string) $t['tea']->public_id, (string) $t['kabsa']->public_id, (string) $t['juice']->public_id], array_column($me['favourites'], 'item'), 'most ordered first');
        $this->assertSame(500, $me['favourites'][0]['price']['minor']);
        $this->assertSame(2, count($me['orders']));
        $this->assertStringNotContainsString('966500000001', json_encode($me), 'the phone is never shown back');

        // a sold-out favourite is left out (the engine's rule)
        $t['tea']->update(['is_sold_out' => true]);
        $this->assertNotContains((string) $t['tea']->public_id, array_column($this->getJson(route('publicView.kiosk.loyalty.me', $t['store']->slug))->json('favourites'), 'item'));
    }

    public function test_reorder_is_priced_from_todays_menu_and_leaves_out_what_is_gone(): void
    {
        $t = $this->make();
        $this->atKiosk($t['store']);
        $this->recognise($t);
        $t['kabsa']->update(['price' => 35]);   // price went up since the order
        $t['juice']->update(['active' => '0']); // gone
        $r = $this->postJson(route('publicView.kiosk.loyalty.reorder', $t['store']->slug), ['order' => $t['order']->id])->assertOk()->json();
        $this->assertSame([[(string) $t['tea']->public_id, 3, 500], [(string) $t['kabsa']->public_id, 1, 3500]],
            array_map(fn ($l) => [$l['item'], $l['qty'], $l['unit_price']['minor']], $r['add']), 'today\'s prices, never the stored 1.00');
        $this->assertSame(['Juice'], $r['unavailable']);

        $withChoices = $this->pastOrder($t['store'], $t['customer'], [[$t['kabsa'], 1, [['group_id' => 1, 'title' => 'Size', 'selected' => [['option' => 'L', 'price' => 2]]]]]]);
        $r = $this->postJson(route('publicView.kiosk.loyalty.reorder', $t['store']->slug), ['order' => $withChoices->id])->assertOk()->json();
        $this->assertSame([[], ['Kabsa']], [$r['add'], array_column($r['choose'], 'name')], 'choices are picked again, today');
    }

    public function test_forgotten_on_not_me_on_the_idle_return_on_the_staff_exit_and_after_ten_minutes(): void
    {
        $t = $this->make();
        $slug = $t['store']->slug;
        $this->atKiosk($t['store']);
        foreach (['forget', 'reset', 'exit', 'time'] as $how) {
            $this->recognise($t);
            $this->getJson(route('publicView.kiosk.loyalty.me', $slug))->assertOk();
            match ($how) {
                'forget' => $this->postJson(route('publicView.kiosk.loyalty.forget', $slug))->assertOk(),
                'reset' => $this->postJson(route('publicView.kiosk.reset', $slug))->assertOk(),
                'exit' => $this->postJson(route('publicView.kiosk.exit', $slug), ['pin' => '4321'])->assertOk(),
                'time' => $this->travel(KioskLoyalty::TTL_SECONDS + 1)->seconds(),
            };
            if ($how === 'exit') {
                $this->atKiosk($t['store']);
            }
            $this->getJson(route('publicView.kiosk.loyalty.me', $slug))->assertNotFound();
            $this->postJson(route('publicView.kiosk.loyalty.reorder', $slug), ['order' => $t['order']->id])->assertStatus(401);
        }
    }

    public function test_the_pass_qr_is_for_the_signed_in_customer_short_lived_single_use_and_this_store_only(): void
    {
        $t = $this->make();
        $b = $this->make();
        $token = app(KioskLoyalty::class)->issuePass($t['store'], $t['customer']);

        $this->atKiosk($b['store']);
        $this->postJson(route('publicView.kiosk.loyalty.pass', $b['store']->slug), ['token' => $token, 'opt_in' => '1'])->assertStatus(422); // another store
        $this->flushSession();
        $this->atKiosk($t['store']);
        $this->postJson(route('publicView.kiosk.loyalty.pass', $t['store']->slug), ['token' => $token])->assertStatus(422); // no opt-in
        $this->postJson(route('publicView.kiosk.loyalty.pass', $t['store']->slug), ['token' => $token.'x', 'opt_in' => '1'])->assertStatus(422);
        $this->postJson(route('publicView.kiosk.loyalty.pass', $t['store']->slug), ['token' => $token, 'opt_in' => '1'])->assertOk();
        $this->assertSame('Sara', $this->getJson(route('publicView.kiosk.loyalty.me', $t['store']->slug))->json('name'));
        $this->postJson(route('publicView.kiosk.loyalty.forget', $t['store']->slug));
        $this->postJson(route('publicView.kiosk.loyalty.pass', $t['store']->slug), ['token' => $token, 'opt_in' => '1'])->assertStatus(422); // used once

        $old = app(KioskLoyalty::class)->issuePass($t['store'], $t['customer']);
        $this->travel(KioskLoyalty::PASS_TTL_SECONDS + 1)->seconds();
        $this->postJson(route('publicView.kiosk.loyalty.pass', $t['store']->slug), ['token' => $old, 'opt_in' => '1'])->assertStatus(422); // expired
    }

    public function test_only_the_customer_signed_in_to_this_store_gets_a_pass_and_never_on_the_kiosk(): void
    {
        $t = $this->make();
        $b = $this->make();
        $this->getJson(route('publicView.kiosk.pass', $t['store']->slug))->assertStatus(401);
        $this->actingAs($t['customer'], 'customer');
        $this->getJson(route('publicView.kiosk.pass', $b['store']->slug))->assertStatus(401); // not their store
        $r = $this->getJson(route('publicView.kiosk.pass', $t['store']->slug))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(KioskLoyalty::PASS_TTL_SECONDS, $r->json('expires_in'));
        $this->withSession(['kiosk.stores' => [(int) $t['store']->id => true]])->getJson(route('publicView.kiosk.pass', $t['store']->slug))->assertNotFound(); // the kiosk itself never mints one
    }

    public function test_one_stores_kiosk_never_reaches_another_stores_customers_or_orders(): void
    {
        $a = $this->make();
        $b = $this->make(); // has its own Sara with the same phone
        $this->atKiosk($a['store']);
        $this->recognise($a);
        $this->postJson(route('publicView.kiosk.loyalty.reorder', $a['store']->slug), ['order' => $b['order']->id])->assertNotFound();
        $this->getJson(route('publicView.kiosk.loyalty.me', $b['store']->slug))->assertForbidden(); // KioskLock: A's kiosk reaches no other store
        $me = $this->getJson(route('publicView.kiosk.loyalty.me', $a['store']->slug))->assertOk()->json();
        $this->assertSame(2, count($me['orders']), 'only A\'s own orders');
        $this->flushSession(); // B's tablet
        $this->atKiosk($b['store']);
        $this->getJson(route('publicView.kiosk.loyalty.me', $b['store']->slug))->assertNotFound()->assertExactJson(['recognised' => false]); // A's proof is not B's
        $this->postJson(route('publicView.kiosk.loyalty.verify', $b['store']->slug), ['phone' => '+966500000001', 'code' => '123456'])->assertStatus(422); // A's code, A's scope
        $this->assertNotEquals((int) $a['store']->account_id, (int) $b['store']->account_id);
    }

    /** @param  array<string, mixed>  $extra */
    private function kioskOrder(Store $store, Menu $m, array $extra = []): TestResponse
    {
        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Sara', 'ordering-type' => 'takeaway', 'pay_via' => 'cod',
        ], $extra));
    }

    private function program(Store $store): void
    {
        LoyaltyProgram::query()->create(['store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1, 'reward_threshold' => 100,
            'reward_description' => 'Ten off', 'reward_discount' => 10.0, 'active' => true]);
    }

    public function test_p6_05_me_shows_the_reward_and_whether_the_balance_reaches_it(): void
    {
        $t = $this->make();
        $this->program($t['store']);
        $this->atKiosk($t['store'])->recognise($t);
        $this->getJson(route('publicView.kiosk.loyalty.me', $t['store']->slug))->assertOk()
            ->assertJsonPath('points', 120)->assertJsonPath('reward.threshold', 100)->assertJsonPath('reward.can_redeem', true)
            ->assertJsonPath('reward.discount.minor', 1000);
    }

    public function test_p6_05_unified_pipeline_same_rules(): void
    {
        config(['settings.storefront_order_pipeline' => 'unified']);
        $this->test_p6_05_a_recognised_kiosk_order_is_theirs_redeems_once_and_the_kiosk_then_forgets_them();
        $this->flushSession(); // an ordinary visitor's browser
        $this->test_p6_05_fix_a_storefront_guest_typing_a_members_phone_cannot_spend_their_points();
    }

    public function test_p6_05_a_recognised_kiosk_order_is_theirs_redeems_once_and_the_kiosk_then_forgets_them(): void
    {
        $t = $this->make();
        $this->program($t['store']);
        $this->atKiosk($t['store'])->recognise($t);
        $this->kioskOrder($t['store'], $t['kabsa'], ['loyalty_redeem' => '1', 'use_wallet' => '1'])->assertOk();
        $order = Order::query()->where('store_id', $t['store']->id)->latest('id')->firstOrFail();
        $this->assertSame('kiosk', $order->source);
        $this->assertSame((int) $t['customer']->id, (int) $order->customer_id);
        $this->assertEqualsWithDelta(10.0, (float) $order->discount_amount, 0.001);
        $this->assertSame(20, (int) LoyaltyAccount::query()->where('customer_id', $t['customer']->id)->value('balance'));
        // the next person at the kiosk is a stranger: no recognition, and their order is nobody's
        $this->getJson(route('publicView.kiosk.loyalty.me', $t['store']->slug))->assertNotFound();
        $this->kioskOrder($t['store'], $t['tea'], ['loyalty_redeem' => '1'])->assertOk();
        $next = Order::query()->where('store_id', $t['store']->id)->latest('id')->firstOrFail();
        $this->assertNull($next->customer_id);
        $this->assertEqualsWithDelta(0.0, (float) $next->discount_amount, 0.001);
        $this->assertSame(20, (int) LoyaltyAccount::query()->where('customer_id', $t['customer']->id)->value('balance'));
    }

    public function test_p6_05_an_unrecognised_kiosk_order_cannot_redeem_anyones_points(): void
    {
        $t = $this->make();
        $this->program($t['store']);
        $this->atKiosk($t['store']);
        $this->kioskOrder($t['store'], $t['kabsa'], ['loyalty_redeem' => '1', 'customer_id' => $t['customer']->id, 'phone-number' => '+966500000001'])->assertOk();
        $order = Order::query()->where('store_id', $t['store']->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001);
        $this->assertSame(120, (int) LoyaltyAccount::query()->where('customer_id', $t['customer']->id)->value('balance'));
    }

    public function test_p6_05_recognition_at_store_a_never_attaches_or_redeems_at_store_b(): void
    {
        $a = $this->make();
        $b = $this->make();
        $this->program($b['store']);
        $this->atKiosk($a['store'])->recognise($a);
        $this->assertFalse($this->kioskOrder($b['store'], $b['kabsa'], ['loyalty_redeem' => '1'])->isOk(), 'KioskLock: A\'s kiosk orders nowhere else');
        $this->flushSession(); // B's tablet
        $this->atKiosk($b['store']);
        $this->kioskOrder($b['store'], $b['kabsa'], ['loyalty_redeem' => '1'])->assertOk();
        $order = Order::query()->where('store_id', $b['store']->id)->latest('id')->firstOrFail();
        $this->assertNull($order->customer_id);
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001);
        $this->assertSame(120, (int) LoyaltyAccount::query()->where('customer_id', $b['customer']->id)->value('balance'));
    }

    public function test_p6_05_with_kiosk_loyalty_off_a_kiosk_order_stays_anonymous(): void
    {
        $t = $this->make();
        $this->program($t['store']);
        $this->atKiosk($t['store'])->recognise($t);
        StoreOption::updateStoreOption($t['store']->id, 'kiosk_loyalty', '0');
        $this->kioskOrder($t['store'], $t['kabsa'], ['loyalty_redeem' => '1'])->assertOk();
        $this->assertNull(Order::query()->where('store_id', $t['store']->id)->latest('id')->firstOrFail()->customer_id);
        $this->assertSame(120, (int) LoyaltyAccount::query()->where('customer_id', $t['customer']->id)->value('balance'));
    }

    public function test_p6_05_fix_a_storefront_guest_typing_a_members_phone_cannot_spend_their_points(): void
    {
        $t = $this->make();
        $this->program($t['store']);
        $this->kioskOrder($t['store'], $t['kabsa'], ['loyalty_redeem' => '1', 'phone-number' => '+966500000001'])->assertOk();
        $order = Order::query()->where('store_id', $t['store']->id)->latest('id')->firstOrFail();
        $this->assertNotSame('kiosk', $order->source);
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001);
        $this->assertSame(120, (int) LoyaltyAccount::query()->where('customer_id', $t['customer']->id)->value('balance'));
    }
}
