<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hr\Models\StaffTask;
use Juwri\Modules\Hr\Models\StaffTaskCompletion;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Browser\Support\CspProbe;
use Tests\Browser\Support\Fixtures;

/**
 * P6-115b: the staff PWA (/staff-app) with the Content-Security-Policy ENFORCED. P6-115 replaced its inline onclick= with
 * data-act="fn" + assets/js/csp-actions.js; these journeys click the real controls and assert the server state changed
 * (the row, not the toast) and that the page raised no CSP violation.
 */
function staffAppScenario(string $role = 'manager'): array
{
    $owner = Fixtures::owner();
    $store = Fixtures::store($owner, 'Staff Diner');
    $staff = StaffMember::create([
        'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Sam Staff', 'role' => $role,
        'email' => 'sam-'.Str::lower(Str::random(5)).'@example.test', 'password' => Hash::make(Fixtures::PASSWORD), 'active' => 1,
    ]);
    $item = Fixtures::item($store, 'Staff Burger', 12.0, Fixtures::menuImage());

    return [$store, $staff, $item];
}

function staffOrder(Store $store, Menu $item, array $attributes = []): Order
{
    $order = Order::create(array_merge([
        'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'customer_name' => 'Walk In', 'created_at' => now(),
    ], $attributes));
    OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

    return $order;
}

function staffSignIn(StaffMember $staff): object
{
    $page = visit('/staff/login');
    $page->type('email', $staff->email)
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIsNot('/staff/login')
        ->navigate('/staff-app')
        ->assertPathIs('/staff-app')
        ->wait(2);
    CspProbe::arm($page);

    return $page;
}

it('serves the staff PWA under an enforced policy and renders the order cards', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item);

    $page = staffSignIn($staff);
    $page->assertNoJavaScriptErrors();
    expect($page->script("() => !!document.querySelector('.card[data-id=\"{$order->id}\"]')"))->toBeTrue('the polled order card is on screen');
    CspProbe::assertClean($page);
});

it('accepts a new order from the staff PWA', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item);

    $page = staffSignIn($staff);
    $page->click(".card[data-id=\"{$order->id}\"] .act.accept");

    CspProbe::until($page, fn () => $order->fresh()->status === 'preparing', 'accept moved the order to preparing');
    CspProbe::assertClean($page, checkHeader: false);
});

it('rejects a new order with a reason from the staff PWA', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item);

    $page = staffSignIn($staff);
    CspProbe::answers($page, true, 'Out of stock');
    $page->click(".card[data-id=\"{$order->id}\"] .act.reject");

    CspProbe::until($page, fn () => $order->fresh()->status === 'cancelled', 'reject cancelled the order');
    $dialogs = CspProbe::dialogs($page);
    expect($dialogs[0][0] ?? null)->toBe('prompt');
    expect($dialogs[0][1] ?? '')->toContain("#{$order->id}");
    CspProbe::assertClean($page, checkHeader: false);
});

it('leaves the order alone when the reject prompt is cancelled', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item);

    $page = staffSignIn($staff);
    CspProbe::answers($page, true, null);
    $page->click(".card[data-id=\"{$order->id}\"] .act.reject")->wait(2);

    expect($order->fresh()->status)->toBe('new');
    expect(CspProbe::dialogs($page))->not->toBe([]);
});

it('advances an order from preparing to ready and expands a card', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item, ['status' => 'preparing']);

    $page = staffSignIn($staff);
    // data-act="toggle" on the card itself: a tap on the card body (not a button) expands it
    $page->click(".card[data-id=\"{$order->id}\"] .top");
    CspProbe::until($page, fn () => $page->script("() => document.querySelector('.card[data-id=\"{$order->id}\"]').classList.contains('open')") === true, 'the card expanded');

    $page->click(".card[data-id=\"{$order->id}\"] .act.advance");
    CspProbe::until($page, fn () => $order->fresh()->status === 'ready', 'advance moved the order to ready');
    CspProbe::assertClean($page, checkHeader: false);
});

it('asks for the bill for a dine-in order', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item, ['type' => 'on-table', 'table_number' => '4', 'status' => 'preparing']);

    $page = staffSignIn($staff);
    $page->click(".card[data-id=\"{$order->id}\"] .act.bill");

    CspProbe::until($page, fn () => $order->fresh()->status === 'call_bill', 'request bill flagged the order');
    CspProbe::assertClean($page, checkHeader: false);
});

it('moves a dine-in order to another table through the prompt', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item, ['type' => 'on-table', 'table_number' => '4', 'status' => 'preparing']);

    $page = staffSignIn($staff);
    CspProbe::answers($page, true, '9');
    $page->click(".card[data-id=\"{$order->id}\"] .act.move");

    CspProbe::until($page, fn () => (string) $order->fresh()->table_number === '9', 'transfer changed the table');
    CspProbe::assertClean($page, checkHeader: false);
});

it('bumps a kitchen ticket line from the Kitchen tab', function () {
    [$store, $staff, $item] = staffAppScenario();
    $order = staffOrder($store, $item, ['status' => 'preparing']);
    $line = OrderItem::where('order_id', $order->id)->first();

    $page = staffSignIn($staff);
    $page->click('.vchip[data-v="kds"]');
    $page->click('#kds .kb.bump');

    CspProbe::until($page, fn () => $line->fresh()->status === 'preparing', 'bump moved the line from queued to preparing');
    CspProbe::assertClean($page, checkHeader: false);
});

it('taps a floor tile, confirms, and requests the bill; cancelling the confirm does nothing', function () {
    [$store, $staff, $item] = staffAppScenario();
    StoreTable::create(['store_id' => $store->id, 'table_no' => 7, 'key' => 'k'.Str::random(10), 'area_type' => 'table', 'label' => 'T7']);
    $order = staffOrder($store, $item, ['type' => 'on-table', 'table_number' => '7', 'status' => 'preparing']);

    $page = staffSignIn($staff);
    $page->click('.vchip[data-v="floor"]');

    CspProbe::answers($page, false);
    $page->click('#floor .ftile')->wait(2);
    expect($order->fresh()->status)->toBe('preparing');
    expect(CspProbe::dialogs($page)[0][0] ?? null)->toBe('confirm');

    CspProbe::answers($page, true);
    $page->click('#floor .ftile');
    CspProbe::until($page, fn () => $order->fresh()->status === 'call_bill', 'the confirmed floor tap requested the bill');
    CspProbe::assertClean($page, checkHeader: false);
});

it('ticks a checklist task from the Tasks tab', function () {
    [$store, $staff] = staffAppScenario();
    $task = StaffTask::create(['store_id' => $store->id, 'title' => 'Wipe the counters', 'active' => true, 'position' => 1]);

    $page = staffSignIn($staff);
    $page->click('.vchip[data-v="tasks"]');
    $page->click('#tasks .tk');

    CspProbe::until($page, fn () => StaffTaskCompletion::where('staff_task_id', $task->id)->exists(), 'the tick was stored');
    CspProbe::assertClean($page, checkHeader: false);
});
