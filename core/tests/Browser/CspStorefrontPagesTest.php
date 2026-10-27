<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\StoreTable;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Tests\Browser\Support\CspProbe;
use Tests\Browser\Support\Fixtures;
use Tests\Support\TestPlans;

/**
 * P6-122b: the storefront sub-pages carry the enforced storefront policy on their own routes now. For every retained theme
 * the store page, the cart and the checkout load with ZERO CSP violations (scripts, requests, form posts) and with every
 * stylesheet, font and image the page asked for actually loaded; the hotel guest pages likewise.
 */

/** Same-origin assets the page requested that failed to load (a CSP-blocked asset never fires load). */
function brokenAssets(object $page): array
{
    return $page->script(<<<'JS'
() => {
    const bad = [];
    document.querySelectorAll('link[rel=stylesheet]').forEach(l => { if (!l.sheet) bad.push('css ' + l.href); });
    document.querySelectorAll('img').forEach(i => { if (i.complete && i.naturalWidth === 0 && (i.getAttribute('src') || '').trim() !== '' && !i.dataset.cspError && !/definitely-missing/.test(i.src)) bad.push('img ' + i.src); });
    return bad;
}
JS);
}

/** Every <style>/<script> in the document carries the header's nonce (an inline one without it would be dead). */
function unnoncedInline(object $page): int
{
    return (int) $page->script("() => [...document.querySelectorAll('script:not([src]):not([type=\"application/ld+json\"]):not([type=\"application/json\"]),style')].filter(e => !e.nonce).length");
}

it('loads the store page, cart and checkout of every theme under the enforced policy with zero violations', function (string $template) {
    $store = Fixtures::store(Fixtures::owner(), 'Csp '.$template, $template);
    Fixtures::item($store, 'Csp Dish', 12.0, Fixtures::menuImage());

    foreach (['/'.$store->slug, '/'.$store->slug.'/cart', '/'.$store->slug.'/checkout'] as $url) {
        $page = visit($url);
        $page->wait(2);
        CspProbe::arm($page);
        $page->script('() => { window.scrollTo(0, document.body.scrollHeight); return true; }');
        $page->wait(1);
        // load-time refusals ("Refused to load/execute/apply …") land in the console before arm() could listen
        $page->assertNoConsoleLogs()->assertNoJavaScriptErrors();
        CspProbe::assertClean($page);
        expect(CspProbe::header($page))->not->toContain("'unsafe-eval'", $url.': the storefront policy has no eval');
        expect(unnoncedInline($page))->toBe(0, $url.': every inline script/style carries the nonce');
        expect(brokenAssets($page))->toBe([], $url.': theme stylesheets and images loaded');
    }
})->with(['pizzaflow', 'feast', 'pulse', 'aurora', 'vivid', 'plate', 'nebula', 'pandabite']);

it('serves the hotel guest pages (info, booking, folio) under the enforced policy with zero violations', function () {
    app()->setLocale('en');
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    $plan = TestPlans::create(['name' => 'H'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
        'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'], 'status' => 1]);
    $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    $store = Store::create(['user_id' => $owner->id, 'slug' => 'csp-hotel-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => 'Csp Hotel',
        'sub_title' => 'x', 'description' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1']);
    StoreSettings::set($store->id, 'hotel_mode', '1');
    StoreSettings::set($store->id, 'hotel_online_booking', 'request');
    StoreSettings::set($store->id, 'hotel_settings', json_encode(['wifi' => ['name' => 'Net', 'password' => 'pw'], 'house_rules' => 'Quiet after 22:00',
        'breakfast' => ['hours' => '07:00 - 10:30', 'cutoff' => '22:00'], 'contact' => ['whatsapp' => '+1 555 010 2222']]));
    $room = StoreTable::create(['store_id' => $store->id, 'table_no' => 101, 'key' => Str::random(10), 'seats' => 2, 'area_type' => 'room', 'label' => 'Room 101']);
    $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Guest', '555-1');

    foreach (['/hotel/'.$store->slug.'/info?table='.$room->key, '/hotel/'.$store->slug.'/book', '/hotel/'.$store->slug.'/folio/'.$stay->token] as $url) {
        $page = visit($url);
        $page->wait(1);
        CspProbe::arm($page);
        $page->wait(1);
        expect($page->content())->not->toContain('404', $url.' rendered');
        $page->assertNoConsoleLogs()->assertNoJavaScriptErrors();
        CspProbe::assertClean($page);
        expect(unnoncedInline($page))->toBe(0, $url.': every inline script/style carries the nonce');
        expect(brokenAssets($page))->toBe([], $url.': stylesheets and images loaded');
    }
});
