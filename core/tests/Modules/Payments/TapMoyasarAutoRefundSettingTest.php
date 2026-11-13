<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Owner decision 2026-10-07: Tap + Moyasar automatic refunds are a per-store setting of the store OWNER, default off.
 * Off: refunds of Tap / Moyasar payments are recorded as manual and nothing is sent. On (with automatic refunds on):
 * the built refund calls are registered for that store only. Platform payments never get them. Staff sessions and
 * reseller bulk edits cannot change either switch.
 */
class TapMoyasarAutoRefundSettingTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Http::fake([
            'api.tap.company/v2/refunds' => Http::response(['id' => 're_tap', 'status' => 'PENDING']),
            'api.moyasar.com/v1/payments/*/refund' => Http::response(['id' => 'pay_m', 'status' => 'refunded', 'amount' => 10000, 'refunded' => 500]),
        ]);
    }

    /** A paid 100 SAR order on $gateway, store credentials, automatic refunds on. */
    private function paid(string $gateway): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction($gateway, 100.0, ['secret_key' => 'sk_test_store'], 'SAR');
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => $gateway,
            'payment_id' => $gateway === 'tap' ? 'chg_'.$tx->id : 'pay_'.$tx->id,
            'details' => $gateway === 'tap' ? ['tap_charge_id' => 'chg_'.$tx->id] : ['moyasar_payment_id' => 'pay_'.$tx->id]])->save();
        Order::whereKey($order->id)->update(['payment_gateway' => $gateway, 'is_paid' => 1, 'currency_code' => 'SAR']);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');

        return [$store, $order->fresh(), $tx->fresh()];
    }

    private function refundCalls(): int
    {
        return Http::recorded(fn (ClientRequest $r) => str_ends_with($r->url(), '/v2/refunds') || str_ends_with($r->url(), '/refund'))->count();
    }

    public function test_off_by_default_refunds_stay_manual_and_nothing_is_sent(): void
    {
        foreach (['tap', 'moyasar'] as $gateway) {
            [$store, $order] = $this->paid($gateway);
            $this->assertArrayNotHasKey($gateway, GatewayRefundService::adapters((int) $store->id));
            $result = GatewayRefundService::refund($order, 5.0);
            $this->assertSame([false, $gateway], [$result['automatic'], $result['gateway']], $gateway);
        }
        $this->assertSame(0, $this->refundCalls());
    }

    public function test_on_the_store_s_tap_and_moyasar_refunds_are_sent_automatically(): void
    {
        foreach (['tap', 'moyasar'] as $gateway) {
            [$store, $order] = $this->paid($gateway);
            StoreOption::updateStoreOption($store->id, 'auto_refunds_tap_moyasar', '1');
            $result = GatewayRefundService::refund($order, 5.0);
            $this->assertTrue($result['automatic'], $gateway.': '.($result['message'] ?? ''));
        }
        $this->assertSame(2, $this->refundCalls());

        // it is that store's choice only: another store, and platform payments, keep them unregistered
        [$other, $otherOrder] = $this->paid('tap');
        $this->assertFalse(GatewayRefundService::refund($otherOrder, 5.0)['automatic']);
        $this->assertArrayNotHasKey('tap', GatewayRefundService::adapters());
        $this->assertSame(2, $this->refundCalls());

        // the opt-in needs automatic refunds on
        StoreOption::updateStoreOption($other->id, 'auto_refunds_tap_moyasar', '1');
        StoreOption::updateStoreOption($other->id, 'auto_refunds_enabled', '0');
        $this->assertFalse(GatewayRefundService::refund($otherOrder->fresh(), 5.0)['automatic']);
        $this->assertSame(2, $this->refundCalls());
    }

    public function test_only_the_owner_changes_the_switches(): void
    {
        config(['payments.show_deferred_gateways' => true]); // GW1 (J51a): the Tap/Moyasar switch only shows while those gateways are offered
        [$store] = $this->paid('tap');
        $owner = Store::findOrFail($store->id)->user;
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        $payload = ['color' => '#111', 'title' => 'Store', 'description' => 't', 'address' => '1 St', 'store_template' => 'aurora',
            'currency_code' => 'SAR', 'currency_sign' => 'SAR', 'currency_pos' => '1', 'auto_refunds_enabled' => '1', 'auto_refunds_tap_moyasar' => '1'];

        $this->actingAs($owner)->put(route('stores.update', $store), $payload);
        $this->assertSame('1', (string) StoreSettings::legacy((int) $store->id, 'auto_refunds_tap_moyasar'));
        $this->actingAs($owner)->get(route('stores.edit', $store))->assertOk()->assertSee('name="auto_refunds_tap_moyasar"', false);

        // a staff session with menu access can save the store form, but not these switches, and does not see them
        $staff = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1, 'permissions' => ['menu_edit' => true]]);
        $this->app['auth']->forgetGuards();
        $asStaff = fn () => $this->actingAs($staff, 'staff')->actingAs($owner, 'web')->withSession(['staff_session_id' => $staff->id, 'dashboard_store_id' => $store->id]);
        $asStaff()->put(route('stores.update', $store), ['auto_refunds_enabled' => '0', 'auto_refunds_tap_moyasar' => '0'] + $payload);
        $this->assertSame(['1', '1'], [(string) StoreSettings::legacy((int) $store->id, 'auto_refunds_enabled'), (string) StoreSettings::legacy((int) $store->id, 'auto_refunds_tap_moyasar')]);
        $page = $asStaff()->get(route('stores.edit', $store));
        if ($page->getStatusCode() === 200) {
            $page->assertDontSee('name="auto_refunds_tap_moyasar"', false)->assertDontSee('name="auto_refunds_enabled"', false);
        }

        // reseller console and fleet bulk edits only write OPTION_KEYS, which leave the owner's money switches out
        foreach (StoreSettingsService::OWNER_KEYS as $key) {
            $this->assertNotContains($key, StoreSettingsService::OPTION_KEYS);
        }
    }
}
