<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Tests\Support\BrewCart;
use Tests\Support\PinsMiddayClock;
use Tests\Support\TestPlans;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/** P3-07: the `brew` kit theme renders a real cafe store page (and its item sheet, tracking page and states board). */
class KitBrewRenderTest extends TestCase
{
    use PinsMiddayClock;
    use PinsMiddayClock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function cafe(): Store
    {
        $this->seed(KitDemoSeeder::class);
        $store = Store::where('slug', 'bloom-coffee-demo')->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        StoreOption::updateStoreOption($store->id, 'store_template', 'brew');

        return $store;
    }

    public function test_the_store_page_renders_with_the_brew_theme(): void
    {
        $this->cafe();
        $html = $this->get('/bloom-coffee-demo')->assertOk()->getContent();

        $this->assertStringContainsString('data-theme-slug="brew"', $html);
        $this->assertStringContainsString('<div class="c-pickup">', $html, 'the pickup hero and the order type are one block');
        $this->assertStringContainsString('c-hero--pickup', $html);
        $this->assertStringContainsString('c-header__search', $html, 'the compact header carries the search');
        $this->assertStringContainsString('c-gcard', $html, 'grid cards');
        $this->assertStringContainsString('c-cartbar__bar', $html, 'the inverted cart bar');
        $this->assertStringNotContainsString('c-header__cover', $html, 'compact: no cover image');
        $this->assertStringNotContainsString('data-section="reorder"', $html, 'a guest has no recent lines: no rail');
    }

    public function test_the_item_sheet_is_the_cafe_pattern_with_the_name_on_the_cup(): void
    {
        $store = $this->cafe();
        $s = new ViewModelScenario($store, 'brew');
        $cart = [BrewCart::flatWhite($s), BrewCart::plain($s)];

        $sheet = $this->get('/bloom-coffee-demo?fragment=item&item='.$cart[0]['item'])->assertOk()->getContent();
        $this->assertStringContainsString('c-sheet--cafe', $sheet);
        $this->assertStringContainsString('c-sheet__headrow', $sheet);
        $this->assertMatchesRegularExpression('/c-group--(seg|chips)/', $sheet, 'short choices are segmented or chips, never plain rows');
        $this->assertStringContainsString('for="note">Name on the cup', $sheet);
        $this->assertStringContainsString('name="note" type="text" maxlength="20"', $sheet);
        $this->assertStringNotContainsString('Note for the kitchen', $sheet);

        // a signed-in customer's name is the note's starting value
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Noura Al-Harbi-Extra-Long-Name', 'phone' => '+966500000777']);
        $sheet = $this->actingAs($customer, 'customer')->get('/bloom-coffee-demo?fragment=item&item='.$cart[0]['item'])->assertOk()->getContent();
        $this->assertStringContainsString('value="Noura Al-Harbi-Extra"', $sheet, 'cut to the 20 characters the field allows');

        // another store type on the same theme keeps the generic kitchen note
        $this->seed(ThemeShowcaseSeeder::class);
        $otherStore = Store::where('slug', 'crave-demo')->firstOrFail();
        // an explicit order (position, id), so the sheet never depends on which row a database returns first
        $item = Menu::query()->where('store_id', $otherStore->id)->where('active', 1)
            ->orderBy('position')->orderBy('id')->firstOrFail()->public_id;
        StoreOption::updateStoreOption($otherStore->id, 'store_template', 'brew');
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($otherStore->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $sheet = $this->get('/crave-demo?fragment=item&item='.$item)->assertOk()->getContent();
        $this->assertStringContainsString('Note for the kitchen', $sheet);
        $this->assertStringNotContainsString('Name on the cup', $sheet);
    }

    public function test_the_order_again_rail_is_for_the_signed_in_customer_and_never_cached_for_anyone_else(): void
    {
        $store = $this->cafe();
        $s = new ViewModelScenario($store, 'brew');
        $cart = [BrewCart::flatWhite($s, 2), BrewCart::plain($s)];
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Noura', 'phone' => '+966500000778']);
        $order = $s->place($cart, ['type' => 'takeaway']);
        $order->forceFill(['customer_id' => $customer->id])->save();

        $guest = $this->get('/bloom-coffee-demo')->assertOk();
        $this->assertStringNotContainsString('c-reorder', $guest->getContent());

        $res = $this->actingAs($customer, 'customer')->get('/bloom-coffee-demo')->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('data-section="reorder"', $html);
        $this->assertStringContainsString('<h2 class="c-reorder__title" id="reorder-title">Order again</h2>', $html);
        $this->assertSame(count($cart), substr_count($html, 'class="c-rpill"'));
        // the line with its choices adds straight to the cart with those choices
        $this->assertStringContainsString('data-juwri-add="'.$cart[0]['item'].'"', $html);
        $this->assertStringContainsString('data-juwri-options="'.implode(' ', $cart[0]['options']).'"', $html);
        $this->assertStringContainsString('aria-label="Add ', $html);
        $this->assertStringContainsString('again"', $html);
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'), "a customer's page carries their own lines: never shared");
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertNull($res->headers->get('ETag'), 'and is never revalidated on the menu etag');
    }

    public function test_the_ready_in_chip_shows_the_kitchens_time_and_hides_without_it(): void
    {
        $store = $this->cafe();
        $this->assertStringNotContainsString('Ready in ~', $this->get('/bloom-coffee-demo')->assertOk()->getContent());

        StoreOption::updateStoreOption($store->id, 'prep_eta_static', '6-10');
        Cache::flush();
        $html = $this->get('/bloom-coffee-demo')->assertOk()->getContent();
        $this->assertStringContainsString('Ready in ~8 min', $html);
        $this->assertStringContainsString('Ready ~8 min', $html, 'the cart bar says it too');
    }

    /** every control the theme offers changes the page (no dead switch) */
    public function test_the_customizer_controls_change_the_page(): void
    {
        $store = $this->cafe();
        $s = new ViewModelScenario($store, 'brew');
        $item = BrewCart::flatWhite($s)['item'];

        $home = $this->get('/bloom-coffee-demo')->assertOk()->getContent();
        $this->assertStringContainsString('data-card="grid"', $home);
        $this->assertStringNotContainsString('data-ratio=', $home);
        $this->assertStringNotContainsString('data-desktop-cart=', $home);

        ThemeSetting::create(['store_id' => $store->id, 'template' => 'brew', 'settings' => [
            'layout.card' => 'list', 'layout.image_ratio' => '4:3', 'layout.desktop_cart' => 'drawer', 'layout.header' => 'cover',
            'menu.show_calories' => false, 'menu.show_allergens' => false, 'texts.hero_title' => 'Fresh from the bar', 'texts.reorder_title' => 'Your usuals',
        ]]);
        request()->attributes->remove('__theme_setting_memo');

        $home = $this->get('/bloom-coffee-demo')->assertOk()->getContent();
        $this->assertStringContainsString('data-card="list"', $home);
        $this->assertStringContainsString('class="c-card c-card--list', $home, 'list: the shared card');
        $this->assertStringNotContainsString('class="c-gcard', $home);
        $this->assertStringContainsString('data-ratio="4:3"', $home);
        $this->assertStringContainsString('data-desktop-cart="drawer"', $home);
        $this->assertStringContainsString('Fresh from the bar', $home);
        $this->assertStringContainsString('c-header__cover', $home, 'cover: the shared cover header');

        $sheet = $this->get('/bloom-coffee-demo?fragment=item&item='.$item)->assertOk()->getContent();
        $this->assertStringNotContainsString('c-fact--allergen', $sheet);
        $this->assertStringNotContainsString(' kcal', $sheet);
    }

    public function test_the_track_page_is_a_pickup_ticket_and_the_states_board_renders(): void
    {
        $store = $this->cafe();
        $s = new ViewModelScenario($store, 'brew');
        $order = $s->place([BrewCart::flatWhite($s, 2, 'Sara'), BrewCart::plain($s)], ['type' => 'takeaway']);
        StoreOption::updateStoreOption($store->id, 'prep_eta_static', '6-10');
        Cache::flush();

        $track = $this->get(route('store.orderTrack', ['token' => $order->track_token]))->assertOk()->getContent();
        $this->assertStringContainsString('data-theme-slug="brew"', $track);
        $this->assertStringContainsString('c-ticket', $track);
        $this->assertStringContainsString('Your order number', $track);
        $this->assertStringContainsString('class="c-progress"', $track);
        $this->assertStringContainsString('Ready in about 8 min', str_replace("\u{00A0}", ' ', $track), 'the kitchen\'s own time (number and unit kept together)');
        $this->assertStringContainsString('c-pickupat', $track, 'where to collect it');

        $kit = app(KitStorefront::class);
        $states = view('store_templates.brew.states', $kit->home($store, Request::create('/bloom-coffee-demo'), 'brew') + ['newTotal' => ['formatted' => 'SAR 1.00'], 'cartTotal' => ['formatted' => 'SAR 2.00']])->render();
        foreach (['Ready for pickup', 'Busy', 'Store closed', 'Prices changed', 'Loading…'] as $card) {
            $this->assertStringContainsString($card, $states);
        }
    }
}
