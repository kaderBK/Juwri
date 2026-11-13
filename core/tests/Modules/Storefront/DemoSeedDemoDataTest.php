<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\PosShift;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;

uses(RefreshDatabase::class);

/** Where this run's credentials file is written (never the repository's own). */
$credentialsPath = null;

/**
 * BL-055 / BL-056: the demo seed's own data, checked where the owner demo
 * actually trips over it.
 *
 *  - one checked-in stay per hotel room (two rows on one room split the folio:
 *    the room's charge lands on the newest stay while the guest's own link
 *    names the other one);
 *  - the demo kitchen takes coupons (the storefront hides the promo box
 *    otherwise, so the demo's DEMO10 / DEMO5OFF codes cannot be tried);
 *  - the owner dashboard opens on the demo kitchen, not on the newest store;
 *  - the POS session finds a drawer with the demo's 200.00 SAR float.
 */
beforeEach(function () use (&$credentialsPath) {
    $credentialsPath = storage_path('app/demo-credentials-test-'.Str::random(12).'.md');
    config(['demo.credentials_path' => $credentialsPath]);
});

afterEach(function () use (&$credentialsPath) {
    if (is_string($credentialsPath) && is_file($credentialsPath)) {
        unlink($credentialsPath);
    }
});

/** The demo store of a vertical of the plan. */
function demoStore(string $vertical): Store
{
    $store = Store::where('slug', DemoOwnerPlan::STORES[$vertical])->first();

    expect($store)->not->toBeNull('the demo seeded the '.$vertical.' store');

    return $store;
}

/**
 * The (store, room) pairs that carry more than one checked-in stay.
 *
 * @return list<string>
 */
function roomsWithTwoCheckedInStays(): array
{
    return HotelStay::where('status', 'checked_in')
        ->select('store_id', 'table_id')
        ->groupBy('store_id', 'table_id')
        ->havingRaw('COUNT(*) > 1')
        ->get()
        ->map(fn ($row): string => $row->store_id.'/'.$row->table_id)
        ->all();
}

it('seeds at most one checked-in stay per hotel room', function () {
    expect(Artisan::call('juwri:demo-seed'))->toBe(0);

    expect(roomsWithTwoCheckedInStays())->toBe([], 'a room never carries two checked-in stays');

    $hotel = demoStore('hotel');
    $room101 = StoreTable::where('store_id', $hotel->id)->where('table_no', 101)->first();

    expect($room101)->not->toBeNull('the demo hotel has room 101');
    expect(HotelStay::where('store_id', $hotel->id)
        ->where('table_id', $room101->id)
        ->where('status', 'checked_in')
        ->count())->toBe(1, 'room 101 has exactly one checked-in guest');
});

it('keeps one checked-in stay per room when the seed runs twice', function () {
    expect(Artisan::call('juwri:demo-seed'))->toBe(0);
    $first = HotelStay::where('status', 'checked_in')->count();

    expect(Artisan::call('juwri:demo-seed'))->toBe(0);

    expect(roomsWithTwoCheckedInStays())->toBe([], 'a second run adds no second stay to a room');
    expect(HotelStay::where('status', 'checked_in')->count())->toBe($first, 'a second run adds no stay at all');
});

it('turns coupons on for the demo kitchen', function () {
    expect(Artisan::call('juwri:demo-seed'))->toBe(0);

    $kitchen = demoStore('restaurant');

    expect((string) StoreOption::getStoreOption($kitchen->id, 'allow_coupons'))->toBe('1');
});

it('opens the owner dashboard on the demo kitchen', function () {
    expect(Artisan::call('juwri:demo-seed'))->toBe(0);

    $owner = User::where('email', DemoOwnerPlan::OWNER_EMAIL)->first();
    expect($owner)->not->toBeNull('the demo seeded the owner login');

    expect((int) $owner->default_store_id)->toBe((int) demoStore('restaurant')->id);
});

it('seeds an opening float for the POS session drawer', function () {
    expect(Artisan::call('juwri:demo-seed'))->toBe(0);

    $kitchen = demoStore('restaurant');
    $shifts = PosShift::where('store_id', $kitchen->id)->where('status', 'open')->get();

    expect($shifts)->not->toHaveCount(0, 'the demo seeds an open drawer');
    expect($shifts->every(fn (PosShift $shift): bool => (float) $shift->opening_float === 200.0))
        ->toBeTrue('the drawer holds the 200.00 SAR float');
    expect($shifts->contains(fn (PosShift $shift): bool => $shift->branch_id === null))
        ->toBeTrue('one drawer is store-wide - the branch an owner POS session resolves');
});
