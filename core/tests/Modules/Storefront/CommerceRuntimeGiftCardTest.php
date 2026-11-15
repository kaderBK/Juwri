<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\GiftCard;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Gift cards on the LIQUID storefront (the commerce runtime).
 *
 * Covers:
 *   - the runtime config flag (`giftCards` from `gift_cards_enabled`);
 *   - the shipped bundles (v1 AND v2) rendering the field and sending the code;
 *   - the positive end-to-end path — a VALID code posted to submitOrder creates
 *     the order and applies the tender.
 */
class CommerceRuntimeGiftCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    /**
     * The runtime config for a store whose options carry the toggle.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function config(array $options): array
    {
        $store = new Store(['slug' => 'pizza']);
        $store->id = 5;

        return (new CommerceRuntime)->config([
            'store' => $store,
            'postOptions' => (object) $options,
            'total_menus' => [],
            'flags' => ['allow_order' => true, 'takeaway' => true, 'delivery' => true, 'payment' => true],
            'customer' => null,
            'table' => null,
        ]);
    }

    public function test_the_flag_follows_the_store_toggle(): void
    {
        $this->assertTrue($this->config(['gift_cards_enabled' => '1'])['flags']['giftCards']);
        $this->assertTrue($this->config(['gift_cards_enabled' => true])['flags']['giftCards']);
        $this->assertFalse($this->config(['gift_cards_enabled' => '0'])['flags']['giftCards']);
        $this->assertFalse($this->config([])['flags']['giftCards']);
    }

    public function test_the_label_is_translatable(): void
    {
        $i18n = $this->config([])['i18n'];

        $this->assertArrayHasKey('Gift card code', $i18n);
        $this->assertNotSame('', trim((string) $i18n['Gift card code']));
    }

    public function test_both_bundles_render_the_field_and_send_the_code(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $path = base_path('../assets/commerce-runtime/'.$version.'/commerce.js');
            $this->assertFileExists($path);
            $js = (string) file_get_contents($path);

            $this->assertStringContainsString('CFG.flags.giftCards', $js, $version.': the field is not gated by the flag');
            $this->assertStringContainsString('data-mb-gift-card-input', $js, $version.': the field has no hook');
            $this->assertStringContainsString('fd.append("gift_card_code"', $js, $version.': the code is never sent');
            $this->assertStringNotContainsString('gift_card_amount', $js, $version.': the client must not price the tender');

            $node = trim((string) shell_exec('command -v node 2>/dev/null'));
            if ($node !== '') {
                exec(escapeshellarg($node).' --check '.escapeshellarg($path).' 2>&1', $out, $code);
                $this->assertSame(0, $code, implode("\n", $out));
            }
        }
    }

    /**
     * A store with a menu, a plan that allows ordering and a funded card.
     *
     * @return array{owner: User, r: Store, menu: Menu}
     */
    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($r->id, 'restaurant_send_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    /** A live card with $value on it. */
    private function card(Store $r, float $value): GiftCard
    {
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, $value, 'stripe');
        GiftCardService::activate($card, $txn);

        return $card->fresh();
    }

    public function test_a_valid_code_at_submit_order_creates_the_order_and_applies_the_tender(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->make();
        $card = $this->card($r, 25.0);

        $res = $this->postJson(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Jane', 'ordering-type' => 'takeaway', 'phone-number' => '555-1',
            'pay_via' => 'cash', 'tip_amount' => '0', 'gift_card_code' => $card->code,
        ]);

        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotNull($order, 'the order was not created');
        $this->assertEqualsWithDelta(10.0, (float) $order->gift_card_applied, 0.001, 'the tender was not applied');
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->amount_due, 0.001, 'the due was not reduced');
        $this->assertEqualsWithDelta(15.0, (float) $card->fresh()->balance(), 0.001, 'the card was not debited');
    }

    public function test_no_code_leaves_the_order_untouched(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->make();

        $res = $this->postJson(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Jane', 'ordering-type' => 'takeaway', 'phone-number' => '555-1',
            'pay_via' => 'cash', 'tip_amount' => '0',
        ]);

        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(0.0, (float) $order->gift_card_applied, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $order->amount_due, 0.001);
    }

    public function test_a_store_a_gift_card_is_refused_at_store_b(): void
    {
        ['r' => $storeA] = $this->make();
        ['r' => $storeB, 'menu' => $menuB] = $this->make();
        $cardA = $this->card($storeA, 25.0);

        $res = $this->postJson(route('store.submitOrder', $storeB), [
            'items' => json_encode([['id' => $menuB->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Jane', 'ordering-type' => 'takeaway', 'phone-number' => '555-1',
            'pay_via' => 'cash', 'tip_amount' => '0', 'gift_card_code' => $cardA->code,
        ]);

        $res->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $storeB->id)->count());
        $this->assertEqualsWithDelta(25.0, (float) $cardA->fresh()->balance(), 0.001);
    }

    public function test_an_invalid_code_is_refused_at_submit_order(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->make();
        $card = $this->card($r, 25.0);

        $res = $this->postJson(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Jane', 'ordering-type' => 'takeaway', 'phone-number' => '555-1',
            'pay_via' => 'cash', 'tip_amount' => '0', 'gift_card_code' => 'GC-INVALID-9999',
        ]);

        $res->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
        $this->assertEqualsWithDelta(25.0, (float) $card->fresh()->balance(), 0.001);
    }
}
