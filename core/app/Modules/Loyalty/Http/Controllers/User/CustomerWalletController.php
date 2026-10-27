<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\AmountInput;
use Juwri\Modules\Payments\Contracts\CustomerGateways;

/**
 * Customer store-credit wallet — balance readout + self-service gateway top-up.
 *
 * Top-up reuses the exact machinery gift-card purchases use: create a pending
 * Transaction (CustomerWalletService::initiateTopup) then hand off to the
 * installed gateway driver's ::pay(). On a confirmed charge the shared
 * CheckoutController::updateUserPlan choke point credits the ledger.
 *
 * All routes are auth:customer. The wallet is per (customer, store); a signed-in
 * customer only ever touches their OWN store's wallet, so the store is pinned to
 * the authed customer's store_id — the {storeId} in the URL must match.
 */
class CustomerWalletController extends Controller
{
    /** The store for this request, guarded to the authed customer's own store. */
    private function store(int|string $storeId): Store
    {
        $customer = auth('customer')->user();
        abort_unless($customer !== null, 403);
        $store = Store::find($storeId);
        abort_unless($store && (int) $customer->store_id === (int) $store->id, 404);
        // Feature gate: a store not entitled to customer_wallet has no wallet UI.
        abort_unless(FeatureAccess::storeAllows($store->id, 'customer_wallet'), 404);

        return $store;
    }

    /** JSON balance for the storefront account screen. */
    public function balance(int|string $storeId): JsonResponse
    {
        $store = $this->store($storeId);
        $customer = auth('customer')->user();

        // P1-23: the store's own gateways — a top-up is paid to the store, never to the platform.
        $gateways = app(CustomerGateways::class)->forStore((int) $store->id)
            ->map(fn ($g) => [
                'id' => $g->id,
                'title' => $g->payment_title ?? ucfirst(str_replace('_', ' ', $g->payment_folder)),
            ])->values();

        return response()->json([
            'balance' => CustomerWalletService::balanceFor($customer),
            'currency' => store_options($store->id, 'currency_code') ?: config('settings.currency_code'),
            'symbol' => cur_isolate($store->currency_sign ?? config('settings.currency_sign', '$')),
            'presets' => [10, 25, 50, 100],
            'gateways' => $gateways,
            'topupUrl' => route('publicView.wallet.topup', $store->id),
            'startUrl' => route('publicView.wallet.topup.start', $store->id),
        ]);
    }

    /** Top-up page: amount + installed gateway picker. */
    public function show(int|string $storeId): View
    {
        $store = $this->store($storeId);
        $customer = auth('customer')->user();

        return view('store_templates._partials.wallet-topup', [
            'store' => $store,
            'gateways' => app(CustomerGateways::class)->forStore((int) $store->id),
            'balance' => CustomerWalletService::balanceFor($customer),
            'currency' => store_options($store->id, 'currency_code') ?: config('settings.currency_code'),
        ]);
    }

    /** Start a top-up → hand off to the chosen gateway. */
    public function topup(Request $request, int|string $storeId): mixed
    {
        $store = $this->store($storeId);
        $customer = auth('customer')->user();

        // Bug batch 9 · 4: exactly the store currency's precision (KWD 0.001, USD 0.01, JPY 1) — the top-up is stored
        // and charged in it (CustomerWalletService::initiateTopup).
        $currency = store_options($store->id, 'currency_code') ?: config('settings.currency_code');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000', AmountInput::precisionRule($currency)],
            'payment_method' => ['required'],
        ]);

        $folder = app(CustomerGateways::class)->resolve((int) $store->id, $data['payment_method']);
        $gateway = $folder !== null ? (object) ['payment_folder' => $folder] : null;
        if (! $gateway) {
            return back()->withInput()->withErrors(['payment_method' => ___('Please choose a valid payment method.')]);
        }

        $result = CustomerWalletService::initiateTopup(
            (int) $store->id,
            (float) $data['amount'], // bug batch 9 · 4: was round(…, 2) — a KWD 7.125 top-up became 7.13
            $gateway->payment_folder,
            (int) $customer->id
        );

        $driver = 'App\\Http\\Controllers\\User\\PaymentMethods\\'
            .str_replace(' ', '', ucwords(str_replace('_', ' ', $gateway->payment_folder)))
            .'Controller';

        return $driver::pay($result['transaction']);
    }
}
