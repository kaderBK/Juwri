<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\VehicleFitment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;
use Juwri\Modules\Verticals\Contracts\VehicleFitments;
use Tests\Support\StorefrontApi\ApiContract;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\Support\ViewModelValidator;
use Tests\TestCase;

/**
 * P3-08b (aisle §13, kit/api `GET /catalog` `vehicle`): `context.vehicle`, `fits_vehicle` on the cards and the
 * detail, and the catalogue's vehicle filter — a confirmed fitment only, this store's fitments only.
 */
class VehicleFitmentFilterTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'sfx_parts_read_0001';

    private Store $store;

    private Menu $pads;

    private Menu $oil;

    private Menu $wiper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $fx = new ApiFixtures;
        $owner = $fx->owner();
        $this->store = $fx->store($owner, 'parts-co', 'Parts Co', 'carparts');
        $fx->token($this->store, self::TOKEN, ['menu:read']);
        $cat = MenuCategory::create(['store_id' => $this->store->id, 'name' => 'Parts', 'active' => 1]);
        $this->pads = $fx->item($this->store, $cat, '019fffff-0000-7000-8000-0000000000a1', 'Brake pads', 40);
        $this->oil = $fx->item($this->store, $cat, '019fffff-0000-7000-8000-0000000000a2', 'Oil 5W30', 25);
        $this->wiper = $fx->item($this->store, $cat, '019fffff-0000-7000-8000-0000000000a3', 'Wiper blade', 9);
        $this->fit($this->pads, 2016, 'Honda', 'Civic');
        $this->fit($this->pads, 2017, 'Honda', 'Civic');
        $this->fit($this->oil, 2016, 'Toyota', 'Corolla', ['engine' => '1.8L', 'vin_prefix' => 'JTDKB20U']);
        // the wiper has no fitments entered (unknown)
    }

    private function fit(Menu $item, int $year, string $make, string $model, array $extra = []): void
    {
        VehicleFitment::create(['menu_id' => $item->id, 'store_id' => $item->store_id, 'year' => $year, 'make' => $make, 'model' => $model] + $extra);
    }

    private function catalog(string $query = '', string $token = self::TOKEN)
    {
        $r = $this->getJson('/api/v1/storefront/catalog'.$query, ['X-Storefront-Token' => $token]);
        if ($r->status() === 200) {
            ApiContract::assertResponse('getCatalog', 'GET', '/api/v1/storefront/catalog', $r);
        }

        return $r;
    }

    /** @return list<string> item names of the response */
    private function names($r): array
    {
        $names = array_column($r->json('catalog.items'), 'name');
        sort($names);

        return $names;
    }

    public function test_without_a_vehicle_nothing_changes(): void
    {
        $r = $this->catalog()->assertOk();
        $this->assertSame(['Brake pads', 'Oil 5W30', 'Wiper blade'], $this->names($r));
        $this->assertArrayNotHasKey('fits_vehicle', array_values($r->json('catalog.items'))[0]);
    }

    public function test_the_vehicle_filter_keeps_only_confirmed_fits_and_marks_the_cards(): void
    {
        $r = $this->catalog('?vehicle='.urlencode('2016|Honda|Civic'))->assertOk();

        $this->assertSame(['Brake pads'], $this->names($r), 'no fitment entered is not a confirmed fit');
        $this->assertTrue($r->json('catalog.items.'.$this->pads->public_id.'.fits_vehicle'));
        $this->assertSame(1, $r->json('catalog.total'));
    }

    public function test_matching_ignores_case_and_year_and_model_must_agree(): void
    {
        $this->assertSame(['Brake pads'], $this->names($this->catalog('?vehicle='.urlencode('2017|honda|CIVIC'))));
        $this->assertSame([], $this->names($this->catalog('?vehicle='.urlencode('2018|Honda|Civic'))));
        $this->assertSame([], $this->names($this->catalog('?vehicle='.urlencode('2016|Honda|Accord'))));
        $this->assertSame(['Oil 5W30'], $this->names($this->catalog('?vehicle='.urlencode('2016|Toyota|Corolla|LE|1.8L'))), 'a fitment without a submodel fits every submodel');
        $this->assertSame([], $this->names($this->catalog('?vehicle='.urlencode('2016|Toyota|Corolla|LE|2.0L'))), 'an engine that is named must agree');
    }

    public function test_a_vehicle_without_an_engine_still_fits_a_row_that_names_one(): void
    {
        // regression (found by the e2e journey): the oil row names 1.8L; a shopper who picked only year/make/model must see it
        $this->assertSame(['Oil 5W30'], $this->names($this->catalog('?vehicle='.urlencode('2016|Toyota|Corolla'))));
    }

    public function test_an_empty_submodel_or_engine_counts_as_not_given(): void
    {
        $lookup = app(VehicleFitments::class);
        $vehicle = ['label' => '2016 Toyota Corolla', 'year' => 2016, 'make' => 'Toyota', 'model' => 'Corolla', 'submodel' => '', 'engine' => ' '];

        $this->assertSame([(int) $this->oil->id => true], $lookup->fits($this->store, [(int) $this->oil->id], $vehicle), 'the oil row names 1.8L: an empty engine must not drop it');
    }

    public function test_a_vin_matches_the_vin_prefix_of_a_fitment(): void
    {
        $this->assertSame(['Oil 5W30'], $this->names($this->catalog('?vehicle='.urlencode('vin:JTDKB20U123456789'))));
        $this->assertSame([], $this->names($this->catalog('?vehicle='.urlencode('vin:WF0AB123456789012'))));
    }

    public function test_an_invalid_vehicle_is_422_and_filters_combine(): void
    {
        foreach (['abc', '2016|Honda', '19|Honda|Civic', 'vin:short', '3000|Honda|Civic', str_repeat('a', 200)] as $bad) {
            $this->catalog('?vehicle='.urlencode($bad))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        $this->assertSame([], $this->names($this->catalog('?vehicle='.urlencode('2016|Honda|Civic').'&in_stock=true&price_max=1000')), 'AND with the price filter');
        $this->assertSame(['Brake pads'], $this->names($this->catalog('?vehicle='.urlencode('2016|Honda|Civic').'&q=brake')));
    }

    public function test_the_cursor_of_a_vehicle_query_is_not_valid_for_another_vehicle(): void
    {
        $a = $this->catalog('?per_page=1&vehicle='.urlencode('2016|Honda|Civic').'&sort=price_asc');
        $this->assertNull($a->json('next_cursor'));
        $b = $this->catalog('?per_page=1&sort=price_asc')->assertOk();
        $cursor = $b->json('next_cursor');
        $this->assertNotNull($cursor);
        $this->catalog('?per_page=1&sort=price_asc&vehicle='.urlencode('2016|Honda|Civic').'&cursor='.$cursor)->assertStatus(422);
    }

    public function test_another_stores_fitments_never_count(): void
    {
        $fx = new ApiFixtures;
        $other = $fx->store($fx->owner(), 'other-parts', 'Other', 'carparts');
        $fx->token($other, 'sfx_other_read', ['menu:read']);
        $cat = MenuCategory::create(['store_id' => $other->id, 'name' => 'B', 'active' => 1]);
        $theirs = $fx->item($other, $cat, '019fffff-0000-7000-8000-0000000000b1', 'Their pads', 30);
        $this->fit($theirs, 2016, 'Honda', 'Civic');
        // a fitment row of the other store that points at THIS store's item (corrupt data): still not counted here
        VehicleFitment::create(['menu_id' => $this->wiper->id, 'store_id' => $other->id, 'year' => 2016, 'make' => 'Honda', 'model' => 'Civic']);

        $mine = $this->catalog('?vehicle='.urlencode('2016|Honda|Civic'));
        $this->assertSame(['Brake pads'], $this->names($mine));
        $theirsRes = $this->catalog('?vehicle='.urlencode('2016|Honda|Civic'), 'sfx_other_read');
        $this->assertSame(['Their pads'], $this->names($theirsRes));
    }

    public function test_a_store_without_fitments_ignores_the_vehicle(): void
    {
        $fx = new ApiFixtures;
        $shop = $fx->store($fx->owner(), 'plain-shop', 'Plain', 'retail');
        $fx->token($shop, 'sfx_plain_read', ['menu:read']);
        $cat = MenuCategory::create(['store_id' => $shop->id, 'name' => 'B', 'active' => 1]);
        $fx->item($shop, $cat, '019fffff-0000-7000-8000-0000000000c1', 'Shirt', 30);

        $r = $this->catalog('?vehicle='.urlencode('2016|Honda|Civic'), 'sfx_plain_read')->assertOk();
        $this->assertSame(['Shirt'], $this->names($r));
        $this->assertArrayNotHasKey('fits_vehicle', array_values($r->json('catalog.items'))[0]);
        $this->catalog('?vehicle=garbage', 'sfx_plain_read')->assertOk();
    }

    public function test_the_item_and_the_store_answers_carry_the_vehicle(): void
    {
        $key = urlencode('2016|Honda|Civic');
        $item = $this->getJson('/api/v1/storefront/items/'.$this->pads->public_id.'?vehicle='.$key, ['X-Storefront-Token' => self::TOKEN])->assertOk();
        ApiContract::assertResponse('getItem', 'GET', '/api/v1/storefront/items/'.$this->pads->public_id, $item);
        $this->assertTrue($item->json('item.fits_vehicle'));
        $none = $this->getJson('/api/v1/storefront/items/'.$this->wiper->public_id.'?vehicle='.$key, ['X-Storefront-Token' => self::TOKEN])->assertOk();
        $this->assertNull($none->json('item.fits_vehicle'));
        $this->assertArrayHasKey('fits_vehicle', $none->json('item'));
        $no = $this->getJson('/api/v1/storefront/items/'.$this->pads->public_id, ['X-Storefront-Token' => self::TOKEN])->assertOk();
        $this->assertArrayNotHasKey('fits_vehicle', $no->json('item'));

        $store = $this->getJson('/api/v1/storefront/store?vehicle='.$key, ['X-Storefront-Token' => self::TOKEN])->assertOk();
        ApiContract::assertResponse('getStore', 'GET', '/api/v1/storefront/store', $store);
        $this->assertSame(['label' => '2016 Honda Civic', 'year' => 2016, 'make' => 'Honda', 'model' => 'Civic'], $store->json('context.vehicle'));
    }

    public function test_the_view_model_has_context_vehicle_and_fits_vehicle_and_matches_the_schema(): void
    {
        $vm = app(ViewModelBuilder::class)->build(new ViewModelRequest(store: $this->store, locale: 'en', page: 'home', vehicle: '2016|Honda|Civic'));

        $this->assertSame('2016 Honda Civic', $vm['context']['vehicle']['label']);
        $this->assertTrue($vm['catalog']['items'][$this->pads->public_id]['fits_vehicle']);
        $this->assertFalse($vm['catalog']['items'][$this->oil->public_id]['fits_vehicle']);
        $this->assertNull($vm['catalog']['items'][$this->wiper->public_id]['fits_vehicle']);
        $this->assertSame([], ViewModelValidator::errorsForJson(ViewModelJson::encode($vm)));

        $item = app(ViewModelBuilder::class)->build(new ViewModelRequest(store: $this->store, locale: 'en', page: 'item', item: (string) $this->pads->public_id, vehicle: '2016|Honda|Civic'));
        $this->assertTrue($item['page']['item']['fits_vehicle']);
        $this->assertSame([], ViewModelValidator::errorsForJson(ViewModelJson::encode($item)));

        $plain = app(ViewModelBuilder::class)->build(new ViewModelRequest(store: $this->store, locale: 'en', page: 'home'));
        $this->assertArrayNotHasKey('vehicle', $plain['context']);
        $stale = app(ViewModelBuilder::class)->build(new ViewModelRequest(store: $this->store, locale: 'en', page: 'home', vehicle: 'not a vehicle'));
        $this->assertArrayNotHasKey('vehicle', $stale['context'], 'a stale link never breaks the page');
    }

    public function test_the_contract_resolves_keys_strictly(): void
    {
        $lookup = app(VehicleFitments::class);
        $this->assertTrue($lookup->enabled($this->store));
        $this->assertSame(['label' => '2016 Honda Civic', 'year' => 2016, 'make' => 'Honda', 'model' => 'Civic'], $lookup->resolve($this->store, ' 2016 | Honda |  Civic '));
        $this->assertSame(['label' => 'JTDKB20U', 'vin' => 'JTDKB20U'], $lookup->resolve($this->store, 'vin:jtd-kb20u'));
        foreach (['', '2016', '2016|Honda', 'x|Honda|Civic', '2016||Civic', 'vin:IOQ12345', '2016|Honda|Civic|a|b|c'] as $bad) {
            $this->assertNull($lookup->resolve($this->store, $bad), $bad);
        }
    }
}
