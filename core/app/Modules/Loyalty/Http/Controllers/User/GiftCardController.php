<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Services\FeatureRegistry;
use App\Services\GiftCardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\CustomerGateways;

/**
 * T2 gift cards — customer-facing purchase. A buyer picks an amount + recipient and
 * pays through an installed gateway; on confirmation the card is funded + activated
 * (Payments' StorePayments::activateGiftCardPurchase). Redemption is handled at checkout (T2-P2).
 */
class GiftCardController extends Controller
{
    private function resolveRestaurant(Request $request, Store $store): Store
    {
        if ($store->exists) {
            return $store;
        }

        return Store::query()
            ->where('id', $request->route('store'))
            ->orWhere('slug', $request->route('store'))
            ->firstOrFail();
    }

    /** Purchase page: amount presets + recipient details. */
    public function buy(Request $request, Store $store)
    {
        $store = $this->resolveRestaurant($request, $store);
        // FIX-2 F2-4: kill first, so a killed platform 403s (the contract
        // FeatureGateAuditTest freezes) rather than pretending the page never
        // existed; an unentitled/switched-off STORE keeps 404ing — a storefront
        // must make an unsold feature invisible, not advertise a locked one.
        abort_unless(FeatureRegistry::isEnabled('gift_cards'), 403, ___('This feature is currently unavailable.'));
        abort_unless(GiftCardService::enabled($store->id), 404);

        // P1-23: the store's own gateways — a gift card is paid to the store, never to the platform.
        $gateways = app(CustomerGateways::class)->forStore((int) $store->id);

        return view('giftcards.buy', [
            'store' => $store,
            'presets' => GiftCardService::presets($store->id),
            'min' => GiftCardService::minAmount($store->id),
            'max' => GiftCardService::maxAmount($store->id),
            'currency' => GiftCardService::currencyFor($store->id),
            'gateways' => $gateways,
        ]);
    }

    /** Start a purchase → gateway. */
    public function purchase(Request $request, Store $store)
    {
        $store = $this->resolveRestaurant($request, $store);
        // FIX-2 F2-4: kill first, so a killed platform 403s (the contract
        // FeatureGateAuditTest freezes) rather than pretending the page never
        // existed; an unentitled/switched-off STORE keeps 404ing — a storefront
        // must make an unsold feature invisible, not advertise a locked one.
        abort_unless(FeatureRegistry::isEnabled('gift_cards'), 403, ___('This feature is currently unavailable.'));
        abort_unless(GiftCardService::enabled($store->id), 404);

        $min = GiftCardService::minAmount($store->id);
        $max = GiftCardService::maxAmount($store->id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:'.$max],
            'payment_method' => ['required'],
            'purchaser_email' => ['nullable', 'email', 'max:191'],
            'recipient_email' => ['nullable', 'email', 'max:191'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:500'],
            'scheduled_send_at' => ['nullable', 'date'],
        ]);

        $folder = app(CustomerGateways::class)->resolve((int) $store->id, $data['payment_method']);
        $gateway = $folder !== null ? (object) ['payment_folder' => $folder] : null;
        if (! $gateway) {
            return back()->withInput()->withErrors(['payment_method' => __('Please choose a valid payment method.')]);
        }

        $result = GiftCardService::initiate(
            $store->id,
            money_round((float) $data['amount'], money_decimals(store_currency((int) $store->id))), // P6-258
            $gateway->payment_folder,
            [
                'purchaser_customer_id' => auth('customer')->id(),
                'purchaser_email' => $data['purchaser_email'] ?? null,
                'recipient_email' => $data['recipient_email'] ?? null,
                'recipient_phone' => $data['recipient_phone'] ?? null,
                'message' => $data['message'] ?? null,
                'scheduled_send_at' => ! empty($data['scheduled_send_at']) ? Carbon::parse($data['scheduled_send_at']) : null,
            ]
        );

        $paymentController = 'App\\Http\\Controllers\\User\\PaymentMethods\\'
            .str_replace(' ', '', ucwords(str_replace('_', ' ', $gateway->payment_folder)))
            .'Controller';

        return $paymentController::pay($result['transaction']);
    }
}
