<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use App\Models\Upgrade;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Symfony\Component\HttpFoundation\Response;

/**
 * WI-06d: merchant-of-record notifications (Paddle, Lemon Squeezy) carry the
 * checkout transaction's attributes back as custom data. Before a signed
 * payment notice may grant or extend anything, the custom data must point at
 * a checkout transaction we created (same id, account, product, method,
 * frequency, amount and currency), and the amount the provider reports as
 * paid must cover that transaction's amount in its currency.
 *
 * "Cover" (paid >= expected) rather than "equal": the provider may add tax on
 * top of our price; paying less than we asked never settles.
 */
final class MorPaymentGuard
{
    /** Attributes that must match between the custom data and our row. */
    private const BOUND = ['user_id', 'product_id', 'transaction_method', 'frequency'];

    /** The checkout transaction the custom data belongs to, or null when it does not match one. */
    public static function checkoutTransaction(mixed $customData): ?Transaction
    {
        if (! is_object($customData)) {
            return null;
        }
        $id = $customData->id ?? null;
        if (! is_scalar($id) || ! ctype_digit((string) $id)) {
            return null;
        }
        $transaction = Transaction::find((int) $id);
        if ($transaction === null) {
            return null;
        }
        foreach (self::BOUND as $field) {
            if ((string) ($customData->{$field} ?? '') !== (string) ($transaction->getRawOriginal($field) ?? '')) {
                return null;
            }
        }
        if (self::currency($customData->currency_code ?? null) !== self::currency($transaction->currency_code)) {
            return null;
        }
        $currency = self::currency($transaction->currency_code);
        if (GatewayMinorUnits::toMinor($customData->amount ?? -1, $currency) !== GatewayMinorUnits::fromMoney($transaction->money('amount'), $currency)) {
            return null;
        }

        return $transaction;
    }

    /**
     * Does the provider's paid total (minor units) in $paidCurrency cover the
     * checkout transaction? $expectedMinor is what we asked the provider to
     * charge, in the same units the provider reports.
     */
    public static function paidCovers(Transaction $transaction, mixed $paidMinor, mixed $paidCurrency, int $expectedMinor): bool
    {
        if (! is_numeric($paidMinor) || ! is_string($paidCurrency) || $paidCurrency === '') {
            return false;
        }
        if (strtoupper($paidCurrency) !== self::currency($transaction->currency_code)) {
            return false;
        }

        return (int) $paidMinor >= $expectedMinor;
    }

    /** Upper-case currency; an empty one on our row means the platform currency. */
    public static function currency(mixed $code): string
    {
        $code = is_string($code) ? trim($code) : '';

        return strtoupper($code !== '' ? $code : platform_currency());
    }

    /**
     * BL-038: a signed renewal whose checkout row is gone — the hourly cleanup deleted paid merchant-of-record
     * checkouts after 3 hours before they were marked settled — still settles against the subscription it renews. Only
     * when the row the custom data names no longer exists (a row that exists but does not match is refused as before:
     * custom data never sets its own price), and only for a subscription our settlements recorded for the account named:
     * its last settled payment stamped with this gateway subscription id ({@see stampSubscription()}), else the
     * account's Upgrade row (unique_id "<gateway>###<subscription>", which only our settlement writes — it may be
     * deleted once the plan lapsed). The plan and billing period must be the ones the custom data names. The price is
     * that last settled payment, never the custom data: returns a stand-in checkout (never saved) with its amount and
     * currency, for the paid-total check; else null.
     */
    public static function renewalCheckout(string $gateway, mixed $customData, mixed $subscriptionId): ?Transaction
    {
        if (! is_object($customData) || ! is_scalar($subscriptionId) || (string) $subscriptionId === ''
            || ($customData->transaction_method ?? null) !== 'membership') {
            return null;
        }
        $id = $customData->id ?? null;
        $userId = $customData->user_id ?? null;
        if (! is_scalar($id) || ! ctype_digit((string) $id) || ! is_scalar($userId) || ! ctype_digit((string) $userId)
            || Transaction::query()->withoutGlobalScopes()->whereKey((int) $id)->exists()) {
            return null;
        }
        $settled = Transaction::query()->withoutGlobalScopes()->where('user_id', (int) $userId)
            ->where('transaction_gatway', $gateway)->where('status', Transaction::STATUS_SUCCESS)
            ->where('transaction_method', 'membership');
        $last = $settled->clone()->latest('id')->limit(100)->get()
            ->first(fn (Transaction $t): bool => (string) data_get($t->details, 'subscription_id') === (string) $subscriptionId);
        if ($last === null) {
            $upgrade = Upgrade::query()->withoutGlobalScopes()->where('user_id', (int) $userId)
                ->where('unique_id', $gateway.'###'.$subscriptionId)->first();
            $last = $upgrade === null ? null : $settled->clone()->where('product_id', $upgrade->sub_id)
                ->where('frequency', (string) $upgrade->interval)->latest('id')->first();
        }
        if ($last === null
            || (string) $last->product_id !== (string) ($customData->product_id ?? '')
            || (string) $last->frequency !== (string) ($customData->frequency ?? '')
            || self::currency($customData->currency_code ?? null) !== self::currency($last->currency_code)) {
            return null;
        }
        $checkout = new Transaction;
        $checkout->forceFill([
            'user_id' => (int) $userId,
            'product_id' => $last->product_id,
            'transaction_method' => 'membership',
            'frequency' => $last->frequency,
            'currency_code' => self::currency($last->currency_code),
            'amount' => $last->amount,
        ]);

        return $checkout;
    }

    /**
     * BL-038: record the gateway subscription a settled payment belongs to (details.subscription_id on its `success`
     * row), so a later renewal can be bound to it even when the checkout row and the Upgrade row are gone. Idempotent.
     */
    public static function stampSubscription(string $gateway, mixed $paymentId, mixed $subscriptionId): void
    {
        if (! is_scalar($paymentId) || (string) $paymentId === '' || ! is_scalar($subscriptionId) || (string) $subscriptionId === '') {
            return;
        }
        $row = Transaction::query()->withoutGlobalScopes()->where('transaction_gatway', $gateway)
            ->where('payment_id', (string) $paymentId)->where('status', Transaction::STATUS_SUCCESS)->first();
        if ($row === null || data_get($row->details, 'subscription_id') !== null) {
            return;
        }
        $details = (array) json_decode((string) json_encode($row->details ?? []), true);
        $details['subscription_id'] = (string) $subscriptionId;
        $row->forceFill(['details' => $details])->save();
    }

    /**
     * BL-038: a signed payment notice (money captured at the gateway) that is not settled is never only a log
     * line — a platform alert is raised, unless this payment is already recorded (a re-delivery).
     */
    public static function refused(string $gateway, mixed $paymentId, string $reason): void
    {
        $paymentId = is_scalar($paymentId) ? (string) $paymentId : '';
        if ($paymentId !== '' && Transaction::query()->withoutGlobalScopes()->where('transaction_gatway', $gateway)->where('payment_id', $paymentId)->exists()) {
            return;
        }
        AlertDispatcher::send('payments.mor_settlement_refused', $gateway.' payment '.($paymentId !== '' ? $paymentId : '(no id)').' was captured but not settled: '.$reason.' — reconcile by hand');
    }

    /**
     * BL-038: once a merchant-of-record settlement answered 200 (settled now, or already settled: a re-delivery), the
     * checkout row it was sold from is marked settled (Transaction::markCheckoutSettled), so the hourly cleanup keeps
     * it for the gateway's later events. `$recurring`: a gateway subscription follows (null = judged from the row).
     */
    public static function markSettled(mixed $response, mixed $customData, ?bool $recurring): void
    {
        $id = is_object($customData) ? ($customData->id ?? null) : null;
        if (! $response instanceof Response || $response->getStatusCode() !== 200 || ! is_scalar($id) || ! ctype_digit((string) $id)) {
            return;
        }
        $userId = $customData->user_id ?? null;
        Transaction::markCheckoutSettled((int) $id, is_scalar($userId) ? (string) $userId : null, $recurring);
    }
}
