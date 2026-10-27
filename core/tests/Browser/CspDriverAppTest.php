<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\Driver;
use App\Models\MkDispatchOffer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Tests\Browser\Support\CspProbe;
use Tests\Browser\Support\Fixtures;

// P6-122: these routes carry the enforced policy themselves (csp.app / csp.dashboard); the tests run against the real routes.

/**
 * P6-115b: the two driver apps with the Content-Security-Policy ENFORCED.
 *   /driver-app  (fleet app): phone + OTP sign-in, shift start/end, accept/reject an offer, status steps, proof-of-delivery
 *                photo upload (the hidden <input type=file data-change="sendPhoto">), tabs.
 *   /driver      (store driver shell): token sign-in, tabs, claim an open order, status buttons.
 * Every assertion reads the database row the click was supposed to change.
 */
function driverStore(): Store
{
    $store = Fixtures::store(Fixtures::owner(), 'Driver Diner');
    config(['settings.admin_allow_driver_app' => true]);

    return $store;
}

function driverOrder(Store $store, array $attributes = []): Order
{
    $order = Order::create(array_merge([
        'store_id' => $store->id, 'type' => 'delivery', 'status' => 'new', 'delivery_status' => 'pending',
        'address' => '12 Palm St', 'customer_name' => 'Hungry Guest', 'phone_number' => '0500000001', 'created_at' => now(),
    ], $attributes));
    OrderItem::create(['order_id' => $order->id, 'item_id' => Fixtures::item($store, 'Delivered Burger '.Str::random(3), 15.0, Fixtures::menuImage())->id, 'quantity' => 1, 'variation' => 0]);

    return $order;
}

/** A SmsOtp that "sends" nothing and accepts the code 123456 (the real service needs an SMS gateway). */
function fakeDriverOtp(): void
{
    app()->instance(SmsOtp::class, new class implements SmsOtp
    {
        public function request(string $phone, OtpScope $scope): array
        {
            return [true, 'sent'];
        }

        public function verify(string $phone, string $code, OtpScope $scope): bool
        {
            return $code === '123456';
        }
    });
}

function fleetDriver(): Driver
{
    return Driver::create([
        'owner_id' => null, 'store_id' => null, 'name' => 'Fleet Faris', 'phone' => '0511122233',
        'token' => hash('sha256', Str::random(40)), 'active' => true, 'is_fleet' => true, 'city_id' => 1,
        'shift_status' => 'offline', 'capacity' => 2, 'active_load' => 0, 'last_lat' => 24.72, 'last_lng' => 46.68,
    ]);
}

function fleetSignIn(): object
{
    $page = visit('/driver-app');
    $page->wait(1);
    CspProbe::arm($page);
    $page->type('#phone', '0511122233')
        ->click('[data-act="requestOtp"]')
        ->type('#code', '123456')
        ->click('[data-act="verifyOtp"]')
        ->wait(2);

    return $page;
}

it('signs a fleet driver in by phone and code, runs the shift buttons and the tabs', function () {
    driverStore();
    fakeDriverOtp();
    $driver = fleetDriver();

    $page = fleetSignIn();
    expect($page->script("() => document.getElementById('scr-home').style.display"))->toBe('block', 'the home screen replaced the login');
    CspProbe::assertClean($page);

    $page->click('#sh-online');
    CspProbe::until($page, fn () => $driver->fresh()->shift_status === 'online', 'Online started the shift');
    $page->click('#sh-break');
    CspProbe::until($page, fn () => $driver->fresh()->shift_status === 'break', 'Break paused the shift');
    $page->click('#sh-offline');
    CspProbe::until($page, fn () => $driver->fresh()->shift_status === 'offline', 'Offline ended the shift');

    $page->click('#tab-earnings');
    CspProbe::until($page, fn () => $page->script("() => document.getElementById('scr-earnings').style.display") === 'block', 'the Earnings tab opened');
    $page->click('#tab-home');
    CspProbe::until($page, fn () => $page->script("() => document.getElementById('scr-home').style.display") === 'block', 'the Jobs tab opened');
    CspProbe::assertClean($page, checkHeader: false);
});

it('rejects a fleet sign-in with a wrong code', function () {
    driverStore();
    fakeDriverOtp();
    fleetDriver();

    $page = visit('/driver-app');
    $page->wait(1);
    CspProbe::arm($page);
    $page->type('#phone', '0511122233')->click('[data-act="requestOtp"]')->type('#code', '000000')->click('[data-act="verifyOtp"]')->wait(1);

    expect($page->script("() => document.getElementById('login-msg').textContent.trim()"))->not->toBe('');
    expect($page->script("() => document.getElementById('scr-home').style.display"))->toBe('none');
    // "Change number" goes back to the phone step
    $page->click('[data-act="showStep"]');
    expect($page->script("() => document.getElementById('login-phone-step').style.display"))->toBe('block');
    CspProbe::assertClean($page, checkHeader: false);
});

it('accepts an offer, walks the delivery steps and uploads the proof photo', function () {
    $store = driverStore();
    StoreOption::updateStoreOption($store->id, 'delivery_pod_mode', 'photo');
    fakeDriverOtp();
    $driver = fleetDriver();
    $order = driverOrder($store);
    MkDispatchOffer::create(['order_id' => $order->id, 'driver_id' => $driver->id, 'status' => 'offered', 'expires_at' => now()->addMinutes(5)]);

    $page = fleetSignIn();
    $page->click('#sh-online');
    CspProbe::until($page, fn () => $driver->fresh()->shift_status === 'online', 'the driver is online');

    $page->click('#offers .accept');
    CspProbe::until($page, fn () => (int) $order->fresh()->driver_id === $driver->id && $order->fresh()->delivery_status === 'assigned', 'accepting the offer assigned the order');

    $page->click('#scr-order .step.next'); // Picked up
    CspProbe::until($page, fn () => $order->fresh()->delivery_status === 'picked_up', 'status moved to picked_up');
    $page->click('#scr-order .step.next'); // En route
    CspProbe::until($page, fn () => $order->fresh()->delivery_status === 'out_for_delivery', 'status moved to out_for_delivery');

    $page->click('#scr-order .step.next'); // Delivered: refused until the proof is in
    $page->wait(1);
    expect($order->fresh()->delivery_status)->toBe('out_for_delivery');
    expect($page->script("() => document.getElementById('proof').style.display"))->toBe('block', 'the proof panel opened');

    $png = sys_get_temp_dir().'/pod-'.Str::random(6).'.png';
    File::put($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $page->attach('#pod-photo', $png);

    CspProbe::until($page, fn () => $order->fresh()->pod_photo !== null, 'the photo was stored');
    CspProbe::until($page, fn () => $order->fresh()->delivery_status === 'delivered', 'the order was delivered after the photo');
    CspProbe::assertClean($page, checkHeader: false);
    File::delete($png);
});

it('rejects an offer and marks a delivery failed through the prompt', function () {
    $store = driverStore();
    fakeDriverOtp();
    $driver = fleetDriver();
    $offered = driverOrder($store);
    $offer = MkDispatchOffer::create(['order_id' => $offered->id, 'driver_id' => $driver->id, 'status' => 'offered', 'expires_at' => now()->addMinutes(5)]);
    $active = driverOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'assigned', 'assigned_at' => now()]);

    $page = fleetSignIn();
    $page->click('#offers .reject');
    CspProbe::until($page, fn () => $offer->fresh()->status !== 'offered', 'rejecting the offer closed it');

    $page->click('#active [data-act="openOrderById"]');
    CspProbe::answers($page, true, 'Nobody home');
    $page->click('#scr-order [data-act="markFailed"]');
    CspProbe::until($page, fn () => $active->fresh()->delivery_status === 'failed', 'Mark failed set the status');
    expect(CspProbe::dialogs($page)[0][0] ?? null)->toBe('prompt');
    CspProbe::assertClean($page, checkHeader: false);
});

it('signs a store driver in with a token, claims an open order and walks the status buttons', function () {
    $store = driverStore();
    StoreOption::updateStoreOption($store->id, 'driver_self_claim', '1');
    $raw = Str::random(40);
    $driver = Driver::create([
        'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'Store Sami', 'phone' => '0522233344',
        'token' => hash('sha256', $raw), 'active' => true,
    ]);
    $order = driverOrder($store);

    $page = visit('/driver');
    $page->wait(1);
    CspProbe::arm($page);
    $page->type('#tok', $raw)->click('[data-act="saveToken"]')->wait(2);
    expect($page->script("() => document.getElementById('app').style.display"))->toBe('block', 'the token opened the app');
    CspProbe::assertClean($page);

    $page->click('#tab-open');
    $page->click('#list [data-act="claim"]');
    CspProbe::until($page, fn () => (int) $order->fresh()->driver_id === $driver->id, 'claim assigned the order to this driver');

    $page->click('#list [data-act="setStatus"][data-args*="picked_up"]');
    CspProbe::until($page, fn () => $order->fresh()->delivery_status === 'picked_up', 'Picked up was stored');
    $page->click('#list [data-act="setStatus"][data-args*="out_for_delivery"]');
    CspProbe::until($page, fn () => $order->fresh()->delivery_status === 'out_for_delivery', 'En route was stored');
    $page->click('#list [data-act="setStatus"][data-args*="delivered"]');
    CspProbe::until($page, fn () => $order->fresh()->delivery_status === 'delivered', 'Delivered was stored');
    CspProbe::assertClean($page, checkHeader: false);
});
