<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Support;

use Juwri\Core\Pci\PaymentPageProbes;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\CcavenueAdapter;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Symfony\Component\HttpFoundation\Response;

/**
 * PCI DSS 4.0 11.6.1: the gateway hand-off pages of this module rendered for the change-detection job — the real
 * views, layouts and scripts, with an unsaved synthetic transaction and placeholder keys (nothing is stored, no
 * provider is called). Registered from PaymentsServiceProvider::boot().
 */
final class GatewayPageProbes
{
    public static function register(): void
    {
        PaymentPageProbes::register('gateway.midtrans', fn (): Response => response()->view('owner.billing.gateways.midtrans', [
            'transaction' => self::transaction(), 'url' => 'https://app.midtrans.com/snap/snap.js', 'mt_client_key' => 'probe',
            'snapToken' => 'probe', 'return_url' => url('/'), 'cancel_url' => url('/'),
        ]));
        PaymentPageProbes::register('gateway.paystack', fn (): Response => response()->view('owner.billing.gateways.paystack', [
            'transaction' => self::transaction(), 'color' => '#111111', 'cancel_url' => url('/'),
            'details' => ['key' => 'pk_probe', 'email' => 'probe@example.com', 'currency' => 'USD', 'amount' => 1000, 'ref' => '0', 'title' => 'Probe', 'description' => 'Probe'],
        ]));
        PaymentPageProbes::register('gateway.razorpay', fn (): Response => response()->view('owner.billing.gateways.razorpay', [
            'transaction' => self::transaction(), 'color' => '#111111', 'cancel_url' => url('/'),
            'details' => ['key' => 'rzp_probe', 'amount' => 1000, 'currency' => 'USD', 'name' => 'Probe', 'image' => '', 'description' => 'Probe',
                'order_id' => 'order_probe', 'buttontext' => 'Pay', 'prefill.name' => '', 'prefill.email' => '', 'theme.color' => '#111111'],
        ]));
        PaymentPageProbes::register('gateway.two_checkout', fn (): Response => response()->view('owner.billing.gateways.two-checkout', [
            'transaction' => self::transaction(), 'checkout_seller_id' => 'probe', 'checkout_public_key' => 'probe', 'sandbox' => false,
            'color' => '#111111', 'cancel_url' => url('/'),
        ]));
        PaymentPageProbes::register('gateway.redirect_form', fn (): Response => response()->view('owner.billing.gateways.redirect-form', [
            'url' => CcavenueAdapter::ENDPOINT, 'data' => ['encRequest' => 'probe', 'access_code' => 'probe'],
        ]));
        PaymentPageProbes::register('gateway.wire_transfer', fn (): Response => response()->view('owner.billing.gateways.wire-transfer', [
            'transaction' => self::transaction(),
        ]));
        PaymentPageProbes::register('storefront.bank_transfer', function (): ?Response {
            $store = PaymentPageProbes::store();
            if ($store === null) {
                return null;
            }
            $tx = self::transaction($store->id);
            [$postOptions, $theme] = self::storefront($tx);

            return response()->view('store_templates.gateways.bank-transfer', [
                'transaction' => $tx, 'store' => $store, 'postOptions' => $postOptions, 'theme' => $theme, 'bankInfo' => 'Probe bank details',
            ]);
        });
        PaymentPageProbes::register('storefront.wallet_transfer', function (): ?Response {
            $store = PaymentPageProbes::store();
            if ($store === null) {
                return null;
            }
            $tx = self::transaction($store->id);
            [$postOptions, $theme] = self::storefront($tx);

            return response()->view('store_templates.gateways.wallet-transfer', [
                'transaction' => $tx, 'store' => $store, 'postOptions' => $postOptions, 'theme' => $theme,
                'isOrder' => true, 'claimUrl' => route('payment.claim', 0),
            ]);
        });
    }

    /** An unsaved pending payment (id 0): an order of $storeId, or a platform payment. */
    public static function transaction(?int $storeId = null): Transaction
    {
        $tx = new Transaction;
        $tx->forceFill([
            'id' => 0,
            'store_id' => $storeId,
            'amount' => 10,
            'currency_code' => $storeId !== null ? (string) (store_options($storeId)->currency_code ?? config('settings.currency_code') ?: 'USD') : platform_currency(),
            'product_name' => 'Payment page check',
            'transaction_method' => $storeId !== null ? 'order' : 'wallet_topup',
            'transaction_description' => 'Payment page check',
        ]);

        return $tx;
    }

    /** @return array{0: object, 1: string} the store's options with its resolved theme, store currency applied */
    private static function storefront(Transaction $tx): array
    {
        $postOptions = store_options((int) $tx->store_id);
        $store = Settlement::store($tx);
        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;
        Settlement::useStoreCurrency($tx);

        return [$postOptions, $theme];
    }
}
