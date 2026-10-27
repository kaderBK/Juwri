<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\Branch;
use Juwri\Core\Settings\StoreSettings;
use Tests\Browser\Support\Fixtures;
use Tests\TestCase;

/**
 * P5-38b — a storefront journey for the P5-34 "free delivery over X also covers branch delivery fees" switch.
 *
 * The owner sets the store's free-delivery threshold (30) and the branch's own delivery fee (7.00) through the
 * real owner screens (the same PUT/POST payloads the store editor at resources/views/owner/stores/edit.blade.php
 * and the branch dialog on the branches screen submit), then a diner on the storefront fills a basket above the
 * threshold and picks delivery at that branch:
 *   - toggle ON  (default) → the branch's 7.00 fee is waived, so the branch quote shows free delivery;
 *   - toggle OFF           → the branch still charges its own 7.00 fee.
 *
 * The storefront runs on the retained `aurora` theme because it renders every total from the server quote
 * (POST {store}/quote) — crave/matcha/glow sum the cart locally and never ask the server, so they cannot show a
 * branch override. The basket is 40.00 and the store's own fee is 5.00, so the numbers tell the two apart:
 *   5.00 is the client-side estimate; 7.00 only ever comes from the server's branch-fee quote; 40.00/47.00 are
 *   the free / not-free totals.
 */
it('shows free delivery when "free delivery over also covers branch fees" is on', function () {
    runFreeDeliveryBranchFeeJourney($this, coversBranchFee: true);
});

it('still charges the branch its 7.00 fee when "free delivery over also covers branch fees" is off', function () {
    runFreeDeliveryBranchFeeJourney($this, coversBranchFee: false);
});

/**
 * One shared journey. `$coversBranchFee` is the owner's P5-34 switch: true = the threshold waives the branch fee.
 */
function runFreeDeliveryBranchFeeJourney(TestCase $test, bool $coversBranchFee): void
{
    $owner = Fixtures::owner();
    $store = Fixtures::store($owner, 'Free Delivery Bistro');
    $item = Fixtures::item($store, 'Family Platter', 40.00);

    // --- Owner: store settings through the real editor endpoint (PUT restaurants.update) ---
    $test->actingAs($owner)->put(route('stores.update', $store->id), [
        'color' => '#111',
        // Keep the slug: applyOwnerUpdate derives one from the title when the
        // field is absent, which would move the store off the URL we visit below.
        'slug' => $store->slug,
        'title' => $store->title,
        'description' => 'A description',
        'address' => '1 Main St',
        // aurora renders the server quote; the other order-type/free-delivery fields mirror the blade's Ordering tab.
        'store_template' => 'aurora',
        'currency_code' => 'USD',
        'currency_sign' => '$',
        'currency_pos' => '0',
        'restaurant_delivery_order' => '1',
        'restaurant_delivery_charge' => '5',
        'restaurant_free_delivery_over' => '30',
        'restaurant_free_delivery_covers_branch_fee' => $coversBranchFee ? '1' : '0',
    ])->assertSessionHasNoErrors();

    // --- Owner: a branch with its own delivery fee, through the real branches screens ---
    $test->actingAs($owner)->post(route('branches.store'), [
        'store_id' => $store->id,
        'name' => 'Downtown',
    ])->assertSessionHasNoErrors();

    $branch = Branch::query()->where('store_id', $store->id)->firstOrFail();
    $test->actingAs($owner)->put(route('branches.update', $branch->id), [
        'name' => 'Downtown',
        'active' => '1',
        'accepting_orders' => '1',
        'delivery_fee' => '7.00',
    ])->assertSessionHasNoErrors();

    // The screens really persisted what the storefront is about to price.
    expect((string) StoreSettings::get((int) $store->id, 'restaurant_free_delivery_over'))->toBe('30')
        ->and((bool) StoreSettings::get((int) $store->id, 'restaurant_free_delivery_covers_branch_fee'))->toBe($coversBranchFee)
        ->and((float) $branch->fresh()->delivery_fee)->toBe(7.0);

    // --- Diner: fill a basket above the threshold and quote delivery at that branch ---
    // The ?item= deep link opens the product sheet straight away (and drops aurora's intro splash).
    $page = visit('/'.$store->slug.'?branch_id='.$branch->id.'&item='.$item->id);

    $page->click('#au-add-to-cart')
        ->click('#au-cart-toggle')
        ->click('#au-ordertype-seg [data-type="delivery"]')
        ->assertSee('40.00') // the basket subtotal (>= the 30 threshold)
        ->assertNoJavaScriptErrors();

    if ($coversBranchFee) {
        // The branch fee is waived: the client's 5.00 estimate is replaced by the server's free delivery.
        $page->assertDontSee('5.00')
            ->assertDontSee('7.00');
    } else {
        // The branch's own 7.00 fee is charged (40.00 + 7.00 = 47.00).
        $page->assertSee('7.00')
            ->assertSee('47.00');
    }
}
