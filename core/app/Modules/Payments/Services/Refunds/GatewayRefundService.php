<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Refunds;

use App\Models\Order;
use Illuminate\Support\Str;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\GatewayAdapter;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\Refunds\Drivers\AdapterRefunder;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;

/**
 * Refund an order back to the diner's ORIGINAL payment (card / gateway), as
 * opposed to issuing store credit (that path stays in CustomerWalletService).
 *
 * Money movement is delegated to a per-gateway {@see GatewayRefunder}. When no
 * driver is registered for the order's gateway — the case for most of the ~30
 * providers today — the refund is recorded as a TRACKED MANUAL refund: the
 * platform marks it settled and the owner completes it in the provider's own
 * dashboard. Registering a driver flips that same provider to automatic with no
 * change to the caller. This keeps the feature honest (it never claims an API
 * refund it did not make) while giving owners one place to record every refund.
 */
class GatewayRefundService
{
    /**
     * Live drivers keyed by PaymentGateway `payment_folder`. Empty by default:
     * add e.g. 'stripe' => new Drivers\StripeRefunder() once a provider's live
     * refund call is implemented and verified against a real account.
     *
     * @return array<string,GatewayRefunder>
     */
    public static function drivers(?int $storeId = null): array
    {
        $drivers = [];
        foreach (config('refunds.drivers', []) as $class) {
            if (is_string($class) && class_exists($class)) {
                $d = app($class);
                if ($d instanceof GatewayRefunder) {
                    $drivers[$d->gateway()] = $d;
                }
            }
        }

        foreach (static::adapters($storeId) as $gateway => $adapter) {
            $drivers[$gateway] ??= new AdapterRefunder($adapter);
        }

        return $drivers;
    }

    /**
     * Payment adapters offering refunds, keyed by `payment_folder` (config
     * refunds.adapters, WI-06). For a store that switched on the Tap + Moyasar opt-in (owner decision 2026-10-07,
     * post option `auto_refunds_tap_moyasar`, default off) the adapters of config refunds.store_opt_in are added too;
     * platform payments (no store) never get them.
     *
     * @return array<string, GatewayAdapter>
     */
    public static function adapters(?int $storeId = null): array
    {
        $adapters = [];
        $classes = (array) config('refunds.adapters', []);
        if ($storeId !== null && $storeId > 0 && (string) (store_options($storeId, 'auto_refunds_tap_moyasar') ?? '') === '1') {
            $classes += (array) config('refunds.store_opt_in', []);
        }
        foreach ($classes as $gateway => $class) {
            if (is_string($class) && class_exists($class)) {
                $adapter = app($class);
                if ($adapter instanceof GatewayAdapter && $adapter->supportsRefund()) {
                    $adapters[(string) $gateway] = $adapter;
                }
            }
        }

        return $adapters;
    }

    /** A store's opt-in to automatic refunds of its orders (owner decision: off by default). */
    public static function autoRefundsEnabledForStore(int $storeId): bool
    {
        return (string) (store_options($storeId, 'auto_refunds_enabled') ?? '') === '1';
    }

    /** The platform's opt-in to automatic refunds of platform payments (plans, add-ons, top-ups, ...). */
    public static function autoRefundsEnabledForPlatform(): bool
    {
        return (string) config('settings.auto_refunds_enabled', '0') === '1';
    }

    /**
     * The gateway `payment_folder` that charged this order, or null if it cannot
     * be determined (cash, charge-to-room, or an order with no online payment).
     * Prefers the order's own stamp, then the successful checkout Transaction.
     */
    public static function resolveGateway(Order $order): ?string
    {
        if (! empty($order->payment_gateway)) {
            return (string) $order->payment_gateway;
        }

        $tx = Transaction::where('product_id', $order->id)
            ->whereNotNull('transaction_method')
            ->where('transaction_method', '!=', 'cust_wallet_topup')
            ->latest('id')
            ->first();

        return $tx?->transaction_method ?: null;
    }

    /**
     * Attempt to refund $amount to the original payment.
     *
     * @return array{automatic:bool,gateway:?string,reference:?string,message:?string,refused?:true}
     *                                                                                               refused=true → refused before any provider call (bug batch 6); record nothing.
     *                                                                                               automatic=true → the provider issued the refund (money is moving);
     *                                                                                               automatic=false → recorded as a tracked manual refund; `message`
     *                                                                                               tells the owner to complete it in the provider dashboard.
     */
    public static function refund(Order $order, float $amount): array
    {
        $gateway = static::resolveGateway($order);
        // Owner decision: no provider refund call unless the store switched automatic refunds on.
        $driver = $gateway && static::autoRefundsEnabledForStore((int) $order->store_id)
            ? (static::drivers((int) $order->store_id)[$gateway] ?? null)
            : null;

        if ($driver) {
            try {
                $res = $driver->refund($order, $amount);
                if (! empty($res['ok'])) {
                    return [
                        'automatic' => true,
                        'gateway' => $gateway,
                        'reference' => $res['reference'] ?? null,
                        'message' => null,
                    ];
                }

                // Bug batch 6: a refund refused before any provider call (wrong currency, more than what is left,
                // inexact amount) is NOT recorded as a manual refund — the caller refuses it.
                return [
                    'automatic' => false,
                    'gateway' => $gateway,
                    'reference' => null,
                    'message' => $res['message']
                        ?? ___('Could not refund automatically — complete this refund in your payment provider.'),
                ] + (! empty($res['refused']) ? ['refused' => true] : []);
            } catch (\Throwable $e) {
                report($e);

                return [
                    'automatic' => false,
                    'gateway' => $gateway,
                    'reference' => null,
                    'message' => ___('Refund could not be sent to the provider — complete it in your payment provider dashboard.'),
                ];
            }
        }

        // No live driver for this provider: record a tracked manual refund.
        return [
            'automatic' => false,
            'gateway' => $gateway,
            'reference' => null,
            'message' => $gateway
                ? ___('Recorded. Complete this refund in your :gateway dashboard to move the money.', ['gateway' => ucfirst(str_replace('_', ' ', $gateway))])
                : ___('Recorded. Refund the diner through the original payment method to move the money.'),
        ];
    }

    /**
     * P3-14: refund a store payment that is not an order (a booking deposit, ...) to its original gateway, in the
     * payment's own currency. Automatic only when the store switched automatic refunds on (owner decision
     * 2026-09-27) and the gateway's adapter is registered — or the E2E Stub gateway, armed (local/testing with
     * E2E_STUBS) — always through {@see RefundGuard} (cap = what was paid minus what was refunded, reserved under the
     * transaction's row lock, exact amounts). Otherwise nothing is sent and the caller records a tracked manual
     * refund. `refused` = refused before any provider call (not a store payment, not settled, over the cap, another
     * currency): the caller must not record a refund.
     *
     * @return array{automatic:bool,gateway:?string,reference:?string,message:?string,refused?:true}
     */
    public static function refundStorePayment(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        $gateway = $transaction->transaction_gatway ? (string) $transaction->transaction_gatway : null;
        $manual = fn (?string $message = null) => [
            'automatic' => false,
            'gateway' => $gateway,
            'reference' => null,
            'message' => $message ?? ($gateway !== null
                ? ___('Recorded. Complete this refund in your :gateway dashboard to move the money.', ['gateway' => ucfirst(str_replace('_', ' ', $gateway))])
                : ___('Recorded. Refund the diner through the original payment method to move the money.')),
        ];
        $refused = fn (string $message) => $manual($message) + ['refused' => true];

        $storeId = (int) $transaction->store_id;
        if ($storeId <= 0 || $transaction->transaction_method === 'order') {
            return $refused(___('Store orders are refunded from the order.'));
        }
        if (! is_finite($amount) || $amount <= 0) {
            return $refused(___('Nothing to refund.'));
        }
        if ($gateway === null || ! static::autoRefundsEnabledForStore($storeId)) {
            return $manual(); // nothing is sent: the owner completes it in the provider dashboard
        }
        if (! in_array($transaction->status, [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED], true)) {
            return $refused(___('This payment is not settled, so there is nothing to refund.'));
        }

        if ($gateway === 'stub') {
            $armed = app()->environment(['local', 'testing']) && filter_var(config('app.e2e_stubs'), FILTER_VALIDATE_BOOLEAN);
            if (! $armed) {
                return $manual();
            }
            $own = Settlement::currency($transaction);
            $result = RefundGuard::run($transaction, $amount, $currency, 'stub', GatewayMinorUnits::decimals($own),
                fn () => ['ok' => true, 'reference' => 'stub-refund-'.$transaction->getKey().'-'.Str::lower(Str::random(8)), 'message' => null]);
        } else {
            $adapter = static::adapters($storeId)[$gateway] ?? null;
            if ($adapter === null) {
                return $manual();
            }
            try {
                $result = $adapter->refund($transaction, $amount, $currency);
            } catch (\Throwable $e) {
                report($e);

                return $manual(___('Refund could not be sent to the provider — complete it in your payment provider dashboard.'));
            }
        }

        if (! empty($result['ok'])) {
            return ['automatic' => true, 'gateway' => $gateway, 'reference' => $result['reference'] ?? null, 'message' => null];
        }

        return ! empty($result['refused'])
            ? $refused((string) ($result['message'] ?? ___('Nothing to refund.')))
            : $manual($result['message'] ?? ___('Could not refund automatically — complete this refund in your payment provider.'));
    }

    /**
     * Refund a platform payment (anything but a store order) to its original
     * gateway. Only when the platform switched automatic refunds on and the
     * gateway's adapter is registered; otherwise nothing is sent and the caller
     * records a manual refund. A transaction is refunded automatically at most
     * once (the provider's reference is kept in its details).
     *
     * @return array{automatic:bool,gateway:?string,reference:?string,message:?string,refused?:true}
     */
    public static function refundTransaction(Transaction $transaction, float $amount): array
    {
        $gateway = $transaction->transaction_gatway ? (string) $transaction->transaction_gatway : null;
        $manual = fn (?string $message = null) => [
            'automatic' => false,
            'gateway' => $gateway,
            'reference' => null,
            'message' => $message ?? ___('Recorded. Refund the diner through the original payment method to move the money.'),
        ];

        if ($transaction->transaction_method === 'order') {
            return $manual(___('Store orders are refunded from the order.'));
        }
        if (! empty($transaction->details->auto_refund)) {
            return $manual(___('This payment has already been refunded.'));
        }
        if (! in_array($transaction->status, [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED], true) || $amount <= 0) {
            return $manual(___('Nothing to refund.'));
        }
        $adapter = $gateway !== null && static::autoRefundsEnabledForPlatform() ? (static::adapters()[$gateway] ?? null) : null;
        if ($adapter === null) {
            return $manual();
        }

        try {
            $result = $adapter->refund($transaction, $amount);
        } catch (\Throwable $e) {
            report($e);

            return $manual(___('Refund could not be sent to the provider — complete it in your payment provider dashboard.'));
        }
        if (empty($result['ok'])) {
            return $manual($result['message'] ?? ___('Could not refund automatically — complete this refund in your payment provider.'))
                + (! empty($result['refused']) ? ['refused' => true] : []);
        }

        $details = (array) ($transaction->details ?? []);
        // Bug batch 6: the amount itself is in RefundGuard's ledger (details.gateway_refunds); `ledgered` keeps it from
        // being counted twice.
        $details['auto_refund'] = ['reference' => $result['reference'] ?? null, 'amount' => $amount, 'at' => now()->toIso8601String(), 'ledgered' => true];
        $transaction->forceFill(['details' => $details])->save();

        return ['automatic' => true, 'gateway' => $gateway, 'reference' => $result['reference'] ?? null, 'message' => null];
    }
}
