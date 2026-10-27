<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Foundation\Application;
use Juwri\Core\Support\LocalizedRoutes;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;
use Tests\Browser\Support\Fixtures;
use Tests\Support\PharmacyMarket;

/**
 * P6-45: the public pharmacy licence block on the Arabic (RTL) storefront. Same Arabic-prefix boot as
 * A11yCheckoutRtlTest ({@see A11yCheckoutRtlBoot}).
 */
trait PharmacyLicenceRtlBoot
{
    protected function beforeBootstrap(Application $app): void
    {
        LocalizedRoutes::forget();

        $app->booting(function (): void {
            config([
                'settings.include_language_code' => true,
                'laravellocalization.localeRoutes' => 'ar',
            ]);
        });
    }
}

uses(PharmacyLicenceRtlBoot::class);

it('shows the licence block with Arabic labels in an RTL document', function () {
    $store = Fixtures::store(Fixtures::owner());
    $store->forceFill(['store_type' => 'pharmacy'])->save();
    Fixtures::item($store, 'دواء');
    PharmacyMarket::open($store);
    PharmacyLicence::query()->where('store_id', $store->id)->update(['responsible_person_name' => 'د. أمل', 'licence_number' => 'LIC-555']);

    $page = visit('/ar/'.$store->slug);
    expect($page->script('() => document.documentElement.dir'))->toBe('rtl');
    $page->wait(1);
    expect($page->script('() => { const b = document.querySelector("[data-pharmacy-licence]"); return b ? b.innerText : null; }'))
        ->toContain('رقم الترخيص')->toContain('LIC-555')->toContain('الصيدلي المسؤول');
    expect($page->script('() => getComputedStyle(document.querySelector("[data-pharmacy-licence]")).direction'))->toBe('rtl');
});
