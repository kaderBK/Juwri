<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\ViewModel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Services\Storefront\OfflineQueuePolicy;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Tests\Support\ViewModelScenario;
use Tests\Support\ViewModelValidator;
use Tests\TestCase;

/**
 * P3-02 money: the view model's quote is the order's total (THEME-CONTRACT §5.2) — same lines, same rules, the
 * charged rows add up exactly — for every demo store type, with a tip, a coupon, a 3-decimal currency with a fixed
 * tax, and a branch price override (which the order path used to charge at the base price). Plus Money
 * formatting, the quote endpoint and the offline-queue flag (owner Q5: on by default at every level).
 */
class ViewModelQuoteTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-10-04 09:00:00', 'UTC'));
        $this->seed(KitDemoSeeder::class);
    }

    private function scenario(string $slug, string $theme): ViewModelScenario
    {
        return new ViewModelScenario(Store::where('slug', $slug)->firstOrFail(), $theme);
    }

    /** @param  array<string, mixed>  $quote */
    private function assertRowsAddUp(array $quote): void
    {
        $sum = 0;
        foreach ($quote['rows'] as $row) {
            if (empty($row['informational'])) {
                $sum += $row['amount']['minor'];
            }
        }
        $this->assertSame($quote['total']['minor'], $sum, 'the charged rows add up to the total');
        $this->assertSame($quote['total']['minor'], array_sum(array_map(fn ($l) => $l['line_total']['minor'], $quote['lines'])) + $sum - $quote['rows'][0]['amount']['minor']);
    }

    public function test_the_quote_is_the_order_total_for_every_new_store_type(): void
    {
        foreach ([['bloom-coffee-demo', 'brew'], ['fresh-mart-demo', 'basket'], ['sidra-salon-demo', 'book']] as [$slug, $theme]) {
            $s = $this->scenario($slug, $theme);
            $cart = $s->cart();
            $type = OrderTypeKeys::toCore($s->build('en')['context']['selected_order_type']);
            $quote = $s->build('en', 'checkout', ['cart' => $cart])['page']['checkout']['quote'];
            $order = $s->place($cart, ['type' => $type]);

            $this->assertRowsAddUp($quote);
            $this->assertSame((int) $order->fresh()->grand_total_minor, $quote['total']['minor'], "{$slug}: quoted total == order total");
            $track = $s->build('en', 'track', ['order' => $order->fresh()])['page']['order']['quote'];
            $this->assertSame($quote['total'], $track['total']);
            $this->assertSame(array_column($quote['lines'], 'line_total'), array_column($track['lines'], 'line_total'));
        }
    }

    public function test_tip_and_coupon_quote_like_the_placed_order(): void
    {
        $s = $this->scenario('bloom-coffee-demo', 'brew');
        Coupon::create([
            'user_id' => $s->store->user_id, 'code' => 'BLOOM10', 'coupon_type' => 'percentage', 'percentage' => 10,
            'limit' => 0, 'used' => 0, 'applies_to' => 'food_order', 'status' => 1,
        ]);
        $cart = $s->cart();
        $type = OrderTypeKeys::toCore($s->build('en')['context']['selected_order_type']);

        $plain = $s->build('en', 'cart', ['cart' => $cart])['page']['cart'];
        $vm = $s->build('en', 'cart', ['cart' => $cart, 'coupon' => 'BLOOM10', 'tipMinor' => 500])['page']['cart'];

        $this->assertRowsAddUp($vm);
        $discount = collect($vm['rows'])->firstWhere('key', 'discount');
        $this->assertSame(-intdiv($plain['rows'][0]['amount']['minor'], 10), $discount['amount']['minor']);
        $this->assertStringStartsWith("\u{2212}", $discount['amount']['formatted']);
        $this->assertSame(500, collect($vm['rows'])->firstWhere('key', 'tip')['amount']['minor']);
        $this->assertTrue($vm['coupon']['applied']);

        $unknown = $s->build('en', 'cart', ['cart' => $cart, 'coupon' => 'NOPE'])['page']['cart'];
        $this->assertFalse($unknown['coupon']['applied']);
        $this->assertSame($plain['total'], $unknown['total']);
    }

    public function test_kwd_with_a_fixed_tax_formats_three_decimals_and_still_adds_up(): void
    {
        $s = $this->scenario('fresh-mart-demo', 'basket');
        StoreSettings::setMany((int) $s->store->id, ['currency_code' => 'KWD', 'currency_sign' => 'KWD', 'restaurant_tax_type' => 'fixed', 'restaurant_taxx_charge' => '0.250']);
        $cart = $s->cart();
        $type = OrderTypeKeys::toCore($s->build('en')['context']['selected_order_type']);
        $vm = $s->build('en', 'checkout', ['cart' => $cart]);
        $quote = $vm['page']['checkout']['quote'];

        $this->assertSame(3, $vm['money']['currency']['exponent']);
        $this->assertArrayNotHasKey('tax', $vm['money'], 'a fixed tax has no rate');
        $this->assertSame(250, collect($quote['rows'])->firstWhere('key', 'tax')['amount']['minor']);
        $this->assertMatchesRegularExpression('/^\x{2068}KWD\x{2069} \d[\d,]*\.\d{3}$/u', $quote['total']['formatted']);
        $this->assertRowsAddUp($quote);
        $order = $s->place($cart, ['type' => $type]);
        $this->assertSame((int) $order->fresh()->grand_total_minor, $quote['total']['minor']);
    }

    public function test_money_formatting_rtl_side_and_negative(): void
    {
        $store = Store::where('slug', 'bloom-coffee-demo')->firstOrFail();
        app()->setLocale('en');
        $en = new MoneyFormat($store);
        app()->setLocale('ar');
        $ar = new MoneyFormat($store);
        app()->setLocale('en');

        $this->assertSame('SAR', $en->currency);
        $this->assertTrue($en->signFirst);
        $this->assertFalse($ar->signFirst, 'RTL puts the sign after the number (currency_rtl_auto)');
        $this->assertSame(['minor' => 1250, 'currency' => 'SAR', 'formatted' => $en->format(1250)], $en->money(1250));
        $this->assertStringContainsString('12.50', $en->format(1250));
        $this->assertStringStartsWith("\u{2212}", $en->format(-1250));
        $this->assertStringEndsWith("\u{2069}", $ar->format(1250));
    }

    public function test_a_branch_price_override_is_shown_quoted_and_charged(): void
    {
        $s = $this->scenario('bloom-coffee-demo', 'brew');
        $cart = $s->cart();
        $simple = end($cart);
        $item = Menu::where('public_id', $simple['item'])->firstOrFail();
        $branch = Branch::where('store_id', $s->store->id)->firstOrFail();
        $override = round((float) $item->price + 3.5, 2);
        BranchMenu::create(['branch_id' => $branch->id, 'menu_id' => $item->id, 'store_id' => $s->store->id, 'available' => true, 'price' => $override]);
        $type = OrderTypeKeys::toCore($s->build('en')['context']['selected_order_type']);

        $home = $s->build('en', 'home', ['branch' => $branch]);
        $this->assertSame((int) round($override * 100), $home['catalog']['items'][$simple['item']]['price']['minor']);

        $quote = $s->build('en', 'cart', ['cart' => [$simple], 'branch' => $branch])['page']['cart'];
        $this->assertSame((int) round($override * 100), $quote['lines'][0]['line_total']['minor']);
        $this->assertRowsAddUp($quote);

        $order = $s->place([$simple], ['type' => $type, 'branch_id' => $branch->id])->fresh();
        $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, 'the order charges the branch price it showed');
        $this->assertEquals($override, (float) $order->items->first()->unit_price);
    }

    public function test_the_quote_endpoint_returns_the_view_model_quote_and_refuses_foreign_lines(): void
    {
        $s = $this->scenario('bloom-coffee-demo', 'brew');
        $cart = $s->cart();
        $type = $s->build('en')['context']['selected_order_type'];

        $response = $this->postJson("/{$s->store->slug}/quote", ['lines' => $cart, 'order_type' => $type]);
        $this->assertSame(200, $response->status(), (string) $response->getContent());
        $response->assertHeader('Cache-Control', 'no-store, private');
        $quote = json_decode((string) $response->getContent(), false)->quote;
        $this->assertSame([], ViewModelValidator::errorsForDef($quote, 'Quote'));
        $this->assertSame($s->build('en', 'cart', ['cart' => $cart])['page']['cart']['total']['minor'], $quote->total->minor);

        $foreign = $this->scenario('fresh-mart-demo', 'basket')->cart();
        $this->postJson("/{$s->store->slug}/quote", ['lines' => [$foreign[0]]])->assertStatus(422)->assertJsonStructure(['message', 'code']);
        $this->postJson("/{$s->store->slug}/quote", ['lines' => [['item' => '12', 'qty' => 1]]])->assertStatus(422);
        $this->postJson('/no-such-store-xyz/quote', ['lines' => $cart])->assertNotFound();
    }

    public function test_offline_queue_is_on_by_default_at_every_level_and_any_level_turns_it_off(): void
    {
        $s = $this->scenario('bloom-coffee-demo', 'brew');
        $policy = app(OfflineQueuePolicy::class);

        $queue = $s->build('en')['context']['offline_queue'];
        $this->assertTrue($queue['enabled'], 'owner Q5: on by default');
        $this->assertSame(30, $queue['expiry_min']);
        $this->assertNotContains('delivery', $queue['order_types']);
        $this->assertSame([], array_diff($queue['order_types'], ['dine_in', 'takeaway', 'pickup']));

        StoreSettings::set((int) $s->store->id, 'offline_queue_enabled', false);
        $this->assertFalse($policy->enabled($s->store));
        $this->assertFalse($s->build('en')['context']['offline_queue']['enabled']);
        StoreSettings::set((int) $s->store->id, 'offline_queue_enabled', true);

        config(['settings.offline_queue_enabled' => '0']);
        $this->assertFalse($policy->enabled($s->store));
        config(['settings.offline_queue_enabled' => '1']);

        Hooks::on(OfflineQueuePolicy::RESELLER_HOOK, 'test-reseller-off', fn () => false);
        $this->assertFalse($policy->enabled($s->store));
        Hooks::on(OfflineQueuePolicy::RESELLER_HOOK, 'test-reseller-off', fn () => null);
        $this->assertTrue($policy->enabled($s->store));

        $grocery = $this->scenario('fresh-mart-demo', 'basket');
        $groceryQueue = $grocery->build('en')['context']['offline_queue'];
        $this->assertSame([], array_intersect($groceryQueue['order_types'], ['delivery', 'ship', 'pickup_slot']), 'delivery and slot orders never queue');
        $this->assertSame([], ViewModelValidator::errorsForJson(ViewModelJson::encode($grocery->build('en'))));
    }

    public function test_the_schema_copy_the_app_ships_is_the_kits(): void
    {
        $this->assertFileEquals(ViewModelValidator::schemaPath(), app_path('Modules/Storefront/Services/ViewModel/viewmodel.schema.json'));
    }

    public function test_empty_maps_are_sent_as_json_objects(): void
    {
        $s = $this->scenario('sidra-salon-demo', 'book');
        $vm = $s->build('en');
        $vm['theme']['texts'] = [];
        $vm['i18n'] = [];
        $vm['catalog']['items'] = [];
        $vm['catalog']['categories'] = [];
        $json = ViewModelJson::encode($vm);

        $this->assertStringContainsString('"texts":{}', $json);
        $this->assertStringContainsString('"i18n":{}', $json);
        $this->assertStringContainsString('"items":{}', $json);
        $this->assertStringContainsString('"categories":[]', $json);
        $this->assertSame([], ViewModelValidator::errorsForJson($json));
    }

    public function test_the_ordering_contract_offers_the_demo_stores_order_types(): void
    {
        $s = $this->scenario('bloom-coffee-demo', 'brew');
        $this->assertNotSame([], app(StorefrontOrdering::class)->orderTypes($s->store));
    }
}
