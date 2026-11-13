<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\ViewModel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Tests\Support\BrewCart;
use Tests\Support\UniquePhone;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-07 (brew): the three things the cafe theme needs from the backend — `context.prep_time_min` (the kitchen's own history),
 * `customer.recent_items` (server-filtered: only this store's, only what is still orderable, only for the signed-in customer) and
 * `features.line_note` (the one-line "Name on the cup", cafe stores only).
 */
class BrewViewModelTest extends TestCase
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

    private function scenario(): ViewModelScenario
    {
        $store = Store::where('slug', 'bloom-coffee-demo')->firstOrFail();
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);

        return new ViewModelScenario($store, 'brew');
    }

    private function customer(Store $store, string $name = 'Noura'): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => $name, 'phone' => UniquePhone::next('+9665')]);
    }

    /** a placed order of this customer (the same path every storefront order takes), then stamped as the customer's */
    private function orderFor(ViewModelScenario $s, Customer $c, array $cart, array $extra = []): Order
    {
        $order = $s->place($cart, $extra + ['type' => OrderTypeKeys::toCore($s->build('en')['context']['selected_order_type'])]);
        $order->forceFill(['customer_id' => $c->id])->save();

        return $order->fresh();
    }

    // ---- prep time ----------------------------------------------------------------------------------------------

    public function test_the_prep_time_comes_from_the_kitchens_own_status_stamps(): void
    {
        $s = $this->scenario();
        $this->assertArrayNotHasKey('prep_time_min', $s->build('en')['context'], 'no history: the chip is hidden');

        $now = Carbon::now();
        for ($i = 0; $i < EtaEstimator::MIN_SAMPLES; $i++) {
            $order = $s->place($s->cart(), ['type' => 'takeaway']);
            $order->forceFill(['status' => 'ready', 'preparing_at' => $now->copy()->subMinutes(30 + $i), 'ready_at' => $now->copy()->subMinutes(22 + $i)])->save();
        }
        Cache::flush();

        $prep = $s->build('en')['context']['prep_time_min'] ?? null;
        $this->assertNotNull($prep);
        $this->assertGreaterThanOrEqual(1, $prep);
        $this->assertLessThanOrEqual(30, $prep, 'about the 8 minutes the history shows (the live load can only stretch it a little)');
        $this->assertSame([], $s::errors($s->build('en')), 'the view model with a prep time is still valid');
    }

    public function test_the_owners_static_range_is_the_fallback_and_another_stores_history_never_counts(): void
    {
        $s = $this->scenario();
        StoreOption::updateStoreOption($s->store->id, 'prep_eta_static', '10-20');
        Cache::flush();
        $this->assertSame(15, $s->build('en')['context']['prep_time_min']);

        $this->seed(ThemeShowcaseSeeder::class);
        $other = new ViewModelScenario(Store::where('slug', 'crave-demo')->firstOrFail(), 'brew');
        $this->assertArrayNotHasKey('prep_time_min', $other->build('en')['context'], "another store's static range does not leak");
    }

    // ---- name on the cup ----------------------------------------------------------------------------------------

    public function test_the_line_note_is_offered_by_cafe_stores_only(): void
    {
        $s = $this->scenario();
        $this->assertSame(['max_length' => 20], $s->build('en')['features']['line_note']);
        $this->assertSame([], $s::errors($s->build('en')));

        $this->seed(ThemeShowcaseSeeder::class);
        $store = new ViewModelScenario(Store::where('slug', 'crave-demo')->firstOrFail(), 'brew');
        $this->assertArrayNotHasKey('line_note', $store->build('en')['features'] ?? []);
    }

    // ---- reorder ------------------------------------------------------------------------------------------------

    public function test_recent_items_are_the_signed_in_customers_last_lines_with_their_choices(): void
    {
        $s = $this->scenario();
        $c = $this->customer($s->store);
        $cart = [BrewCart::flatWhite($s, 2), BrewCart::plain($s)];
        $order = $this->orderFor($s, $c, $cart);

        $anonymous = $s->build('en');
        $this->assertNull($anonymous['customer'], 'a guest has no recent items');

        $vm = $s->build('en', 'home', ['customer' => $c]);
        $recent = $vm['customer']['recent_items'];
        $this->assertCount(count($cart), $recent);
        $this->assertSame([], $s::errors($vm));

        $withChoices = collect($recent)->first(fn ($r) => $r['options'] !== []);
        $this->assertNotNull($withChoices, 'the line with choices keeps them');
        $this->assertTrue($withChoices['complete']);
        $this->assertEqualsCanonicalizing($cart[0]['options'], $withChoices['options'], 'the last choices come back as public ids');
        $this->assertSame($cart[0]['item'], $withChoices['item_id']);
        $this->assertNotSame('', $withChoices['choices_label']);
        $this->assertSame($vm['catalog']['items'][$withChoices['item_id']]['url'], $withChoices['reorder_url']);

        // adding the rows straight to the cart (at the quantities of the order) prices exactly the order that was placed
        $qty = collect($cart)->pluck('qty', 'item');
        $lines = array_map(fn ($r) => ['item' => $r['item_id'], 'qty' => $qty[$r['item_id']], 'variant' => $r['variant'], 'options' => $r['options']], $recent);
        $type = $vm['context']['selected_order_type'];
        $quote = $s->build('en', 'cart', ['cart' => $lines, 'orderType' => OrderTypeKeys::toCore($type)])['page']['cart'];
        $this->assertSame((int) $order->grand_total_minor, $quote['total']['minor']);
    }

    public function test_recent_items_drop_what_is_no_longer_orderable_and_what_is_not_this_stores(): void
    {
        $s = $this->scenario();
        $c = $this->customer($s->store);
        $cart = [BrewCart::flatWhite($s, 2), BrewCart::plain($s)];
        $this->orderFor($s, $c, $cart);

        // sold out: gone
        Menu::where('public_id', $cart[0]['item'])->update(['is_sold_out' => 1]);
        $recent = $s->build('en', 'home', ['customer' => $c])['customer']['recent_items'] ?? [];
        $this->assertNotContains($cart[0]['item'], array_column($recent, 'item_id'));
        $this->assertContains($cart[1]['item'], array_column($recent, 'item_id'));

        // unpublished: gone
        Menu::where('public_id', $cart[1]['item'])->update(['active' => 0]);
        $this->assertArrayNotHasKey('recent_items', $s->build('en', 'home', ['customer' => $c])['customer'], 'nothing orderable: no rail');

        // another store's customer never sees this store's lines, and a customer of another store is not this one's
        $this->seed(ThemeShowcaseSeeder::class);
        $other = Store::where('slug', 'crave-demo')->firstOrFail();
        $stranger = $this->customer($other, 'Stranger');
        $vm = $s->build('en', 'home', ['customer' => $stranger]);
        $this->assertNull($vm['customer'], "a customer of another store is not this store's customer");
    }

    public function test_a_row_whose_choice_is_gone_falls_back_to_the_bare_item_for_the_sheet(): void
    {
        $s = $this->scenario();
        $c = $this->customer($s->store);
        $line = BrewCart::flatWhite($s);
        $this->orderFor($s, $c, [$line]);

        $row = $s->build('en', 'home', ['customer' => $c])['customer']['recent_items'][0];
        $this->assertTrue($row['complete']);
        $this->assertCount(count($line['options']), $row['options']);

        // the milk the customer had is removed from the menu (the options snapshot is by name, so the loss is detectable)
        $milk = $line['options'][1];
        $this->assertSame(1, Modifier::where('public_id', $milk)->count());
        Modifier::where('public_id', $milk)->delete();

        $row = $s->build('en', 'home', ['customer' => $c])['customer']['recent_items'][0];
        $this->assertSame($line['item'], $row['item_id']);
        $this->assertFalse($row['complete'], 'a choice that no longer exists makes the row open the sheet');
        $this->assertSame([], $row['options']);
        $this->assertNull($row['variant']);
        $this->assertArrayNotHasKey('choices_label', $row);
        $this->assertSame([], $s::errors($s->build('en', 'home', ['customer' => $c])));
    }

    public function test_only_the_theme_with_the_reorder_section_pays_for_the_lookup(): void
    {
        $s = $this->scenario();
        $c = $this->customer($s->store);
        $this->orderFor($s, $c, $s->cart());

        $vm = $s->build('en', 'home', ['customer' => $c]);
        $this->assertContains('reorder', $vm['theme']['sections']);
        $this->assertArrayHasKey('recent_items', $vm['customer']);

        // the section switched off in the customizer: no lookup, no key
        ThemeSetting::create(['store_id' => $s->store->id, 'template' => 'brew', 'settings' => ['sections.reorder' => false]]);
        request()->attributes->remove('__theme_setting_memo');
        $vm = $s->build('en', 'home', ['customer' => $c]);
        $this->assertNotContains('reorder', $vm['theme']['sections']);
        $this->assertArrayNotHasKey('recent_items', $vm['customer']);
    }
}
