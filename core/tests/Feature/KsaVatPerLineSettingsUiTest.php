<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-186 (J44 + J44a): the KSA "VAT per line" toggle in the store editor. Forced by the platform (the default): the
 * select is disabled and shows "Set by the platform for ZATCA compliance", and the server ignores an owner change.
 * Not forced: the owner's own toggle, default ON, with the whole-order VAT confirm.
 */
class KsaVatPerLineSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'T', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['store_template' => 'juwri', 'fiscal_country' => 'SA', 'restaurant_taxx_charge' => '15', 'currency_code' => 'SAR'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return [$owner, $store];
    }

    private function select(string $html): string
    {
        // P6-186: P6186_DUMP=<dir> keeps the rendered editor for the screenshot review
        if (($dir = getenv('P6186_DUMP')) && is_dir($dir)) {
            file_put_contents($dir.'/'.$this->name().'-'.substr(md5($html), 0, 6).'.html', $html);
        }
        $this->assertSame(1, preg_match('/<select[^>]*id="tax-engine-ksa"[^>]*>.*?<\/select>\s*(<small[^>]*>[^<]*<\/small>)?/s', $html, $m), 'KSA VAT select missing');

        return $m[0];
    }

    private function saveEngine(User $owner, Store $store, string $engine): void
    {
        $this->actingAs($owner)->put(route('stores.update', $store->id), [
            'color' => '#111', 'slug' => $store->slug, 'title' => $store->title, 'description' => 'D', 'address' => '1 St',
            'store_template' => 'juwri', 'currency_code' => 'SAR', 'currency_sign' => 'SAR', 'currency_pos' => '0',
            'fiscal_country' => 'SA', 'restaurant_taxx_charge' => '15', 'tax_engine' => $engine,
        ])->assertSessionHasNoErrors();
        StoreSettings::flush();
    }

    public function test_forced_by_default_the_toggle_is_disabled_and_the_server_ignores_a_change(): void
    {
        [$owner, $store] = $this->store();
        $this->assertTrue(TaxEngine::ksaForced(), 'J44a: forced when nothing is stored');

        $sel = $this->select($this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<select[^>]*id="tax-engine-ksa"[^>]*disabled/s', $sel);
        $this->assertMatchesRegularExpression('/<option value="vat"\s+selected/', $sel);
        $this->assertStringContainsString('Set by the platform for ZATCA compliance', $sel);

        $this->saveEngine($owner, $store, 'order');
        $this->assertNotSame('order', (string) store_options($store->id, 'tax_engine'), 'server rejects the owner change while forced');
        $this->assertSame(TaxEngine::MODE_LINE, TaxEngine::storeMode(store_options($store->id)));
    }

    public function test_unforced_the_owner_has_the_toggle_default_on_with_the_confirm(): void
    {
        PlatformSettings::set(TaxEngine::FORCE_KSA_LINE, false);
        [$owner, $store] = $this->store();

        $html = $this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()->getContent();
        $sel = $this->select($html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*id="tax-engine-ksa"[^>]*disabled/s', $sel);
        $this->assertMatchesRegularExpression('/<option value="vat"\s+selected/', $sel, 'default ON');
        $this->assertStringNotContainsString('Set by the platform for ZATCA compliance', $sel);
        $this->assertStringContainsString(json_encode('ZATCA invoices and receipts will use whole-order VAT; check with your accountant'), $html);

        $this->saveEngine($owner, $store, 'order');
        $this->assertSame('order', (string) store_options($store->id, 'tax_engine'));
        $this->assertSame(TaxEngine::MODE_ORDER, TaxEngine::storeMode(store_options($store->id)));
        $sel = $this->select($this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<option value="order"\s+selected/', $sel);
    }
}
