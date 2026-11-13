<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreSettingsService;
use Tests\TestCase;

/**
 * J51a: only Stripe and PayPal ship at launch — Tap and Moyasar are deferred.
 * The write seam refuses to enable a deferred gateway until `payments.show_deferred_gateways`
 * is switched on (the reseller editor's view is covered by the Enterprise test suite).
 */
class StoreGatewayHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $title): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => 's'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => $title, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    private function opt(Store $store, string $key): string
    {
        return (string) StoreOption::getStoreOption($store->id, $key);
    }

    public function test_apply_options_refuses_hidden_gateway_install_by_default(): void
    {
        $store = $this->store('Hidden Tap');

        app(StoreSettingsService::class)->applyOptions($store, ['restaurant_tap_install' => '1']);

        $this->assertSame('0', $this->opt($store, 'restaurant_tap_install'));
    }

    public function test_apply_options_allows_hidden_gateway_when_flag_enabled(): void
    {
        config(['payments.show_deferred_gateways' => true]);
        $store = $this->store('Visible Tap');

        app(StoreSettingsService::class)->applyOptions($store, ['restaurant_tap_install' => '1']);

        $this->assertSame('1', $this->opt($store, 'restaurant_tap_install'));
    }

    public function test_apply_options_leaves_stripe_install_untouched(): void
    {
        $store = $this->store('Stripe Store');

        app(StoreSettingsService::class)->applyOptions($store, ['restaurant_stripe_install' => '1']);

        $this->assertSame('1', $this->opt($store, 'restaurant_stripe_install'));
    }
}
