<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\ViewModel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-02 tenancy + data exposure: a view model for store A never carries store B's data — no id, name or order of
 * another store, even when the request names B's item, branch, order or customer — and it exposes public ids only.
 */
class ViewModelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/';

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

    /** @return list<string> public ids and item names that belong to stores other than $store */
    private function foreignNeedles(Store $store): array
    {
        $others = Store::where('id', '!=', $store->id)->pluck('id');
        $items = Menu::withTrashed()->whereIn('store_id', $others)->get(['id', 'public_id', 'name']);
        $own = Menu::where('store_id', $store->id)->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        return array_values(array_filter(array_merge(
            Store::whereIn('id', $others)->pluck('public_id')->all(),
            Branch::whereIn('store_id', $others)->pluck('public_id')->all(),
            MenuCategory::whereIn('store_id', $others)->pluck('public_id')->all(),
            $items->pluck('public_id')->all(),
            ModifierGroup::query()->whereIn('store_id', $others)->pluck('public_id')->all(),
            Modifier::query()->whereIn('store_id', $others)->pluck('public_id')->all(),
            MenuVariant::query()->whereIn('menu_id', $items->pluck('id'))->pluck('public_id')->all(),
            DB::table('orders')->whereIn('store_id', $others)->pluck('public_id')->all(),
            // item names unique to the other stores (a shared word such as "Latte" is not a leak)
            $items->pluck('name')->filter(fn ($n) => strlen((string) $n) > 6 && ! in_array(mb_strtolower((string) $n), $own, true))->all(),
        )));
    }

    public function test_store_a_view_models_never_contain_another_stores_data(): void
    {
        $cafe = $this->scenario('bloom-coffee-demo', 'brew');
        $grocery = $this->scenario('fresh-mart-demo', 'basket');
        $groceryOrder = $grocery->place($grocery->cart());
        $cafeCart = $cafe->cart();
        $cafeOrder = $cafe->place($cafeCart);
        $needles = $this->foreignNeedles($cafe->store);
        $this->assertGreaterThan(100, count($needles));

        $json = '';
        foreach (['en', 'ar'] as $locale) {
            foreach ([
                ['home', []], ['item', ['item' => $cafeCart[0]['item']]], ['cart', ['cart' => $cafeCart]],
                ['checkout', ['cart' => $cafeCart]], ['track', ['order' => $cafeOrder]], ['home', ['kiosk' => true]],
            ] as [$page, $args]) {
                $json .= ViewModelJson::encode($cafe->build($locale, $page, $args));
            }
        }
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString((string) $needle, $json, 'a cafe view model must not contain another store\'s data');
        }
        $this->assertNotNull($groceryOrder->id);
    }

    public function test_another_stores_item_branch_order_and_customer_are_refused_or_ignored(): void
    {
        $cafe = $this->scenario('bloom-coffee-demo', 'brew');
        $grocery = $this->scenario('fresh-mart-demo', 'basket');
        $foreignCart = $grocery->cart();
        $foreignOrder = $grocery->place($foreignCart);
        $foreignBranch = Branch::where('store_id', $grocery->store->id)->firstOrFail();
        $foreignCustomer = Customer::create([
            'account_id' => $grocery->store->account_id, 'store_id' => $grocery->store->id, 'name' => 'Foreign Shopper '.Str::random(4),
        ]);

        $item = $cafe->build('en', 'item', ['item' => $foreignCart[0]['item']]);
        $this->assertSame(['kind' => 'states'], $item['page']);

        $cart = $cafe->build('en', 'cart', ['cart' => $foreignCart]);
        $this->assertSame(['kind' => 'states'], $cart['page'], 'a cart naming another store\'s item has no quote');

        $mixed = $cafe->build('en', 'cart', ['cart' => [$cafe->cart()[0], $foreignCart[0]]]);
        $this->assertSame(['kind' => 'states'], $mixed['page'], 'one foreign line refuses the whole cart');

        $track = $cafe->build('en', 'track', ['order' => $foreignOrder]);
        $this->assertSame(['kind' => 'states'], $track['page']);
        $this->assertStringNotContainsString((string) $foreignOrder->track_token, ViewModelJson::encode($track));

        $home = $cafe->build('en', 'home', ['branch' => $foreignBranch, 'customer' => $foreignCustomer]);
        $this->assertNotSame((string) $foreignBranch->public_id, $home['branch']['current']['public_id']);
        $this->assertNull($home['customer']);
        $this->assertStringNotContainsString($foreignCustomer->name, ViewModelJson::encode($home));
    }

    public function test_a_cart_option_of_another_item_is_refused(): void
    {
        $cafe = $this->scenario('bloom-coffee-demo', 'brew');
        $cart = $cafe->cart();
        $foreignOption = Modifier::query()->where('store_id', Store::where('slug', 'fresh-mart-demo')->value('id'))->value('public_id')
            ?? Modifier::query()->where('store_id', '!=', $cafe->store->id)->value('public_id');
        $this->assertNotNull($foreignOption);
        $cart[0]['options'][] = $foreignOption;

        $this->assertSame(['kind' => 'states'], $cafe->build('en', 'cart', ['cart' => $cart])['page']);
    }

    public function test_only_public_ids_are_exposed(): void
    {
        foreach ([['bloom-coffee-demo', 'brew'], ['fresh-mart-demo', 'basket'], ['sidra-salon-demo', 'book']] as [$slug, $theme]) {
            $s = $this->scenario($slug, $theme);
            $cart = $s->cart();
            $order = $s->place($cart, ['type' => OrderTypeKeys::toCore($s->build('en')['context']['selected_order_type'])]);
            foreach ([['home', []], ['item', ['item' => $cart[0]['item']]], ['checkout', ['cart' => $cart]], ['track', ['order' => $order]]] as [$page, $args]) {
                $vm = $s->build('en', $page, $args);
                $ids = [$vm['store']['public_id'], $vm['branch']['current']['public_id']];
                foreach ($vm['catalog']['categories'] as $category) {
                    $ids = array_merge($ids, [$category['id']], $category['item_ids']);
                }
                foreach ($vm['catalog']['items'] as $key => $card) {
                    $ids[] = (string) $key;
                    $ids[] = $card['id'];
                }
                $ids = array_merge($ids, $vm['features']['featured'] ?? []);
                foreach ($vm['page']['item']['option_groups'] ?? [] as $group) {
                    $ids = array_merge($ids, [$group['id']], array_column($group['options'], 'id'));
                }
                $quote = $vm['page']['checkout']['quote'] ?? $vm['page']['order']['quote'] ?? null;
                $ids = array_merge($ids, array_column($quote['lines'] ?? [], 'item_id'));
                if (isset($vm['page']['item'])) {
                    $ids[] = $vm['page']['item']['id'];
                }
                foreach ($ids as $id) {
                    $this->assertMatchesRegularExpression(self::UUID, (string) $id, "{$slug} {$page}: only UUIDv7 public ids");
                }
                // no internal row id of this store's items appears as an id anywhere
                $json = ViewModelJson::encode($vm);
                $this->assertDoesNotMatchRegularExpression('/"(id|item_id|public_id)":\s*\d+/', $json);
            }
        }
    }
}
