<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Payments\Contracts\AbandonedCheckouts;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\StripeAdapter;

/**
 * BL-038 review: a checkout the buyer will not pay is marked `abandoned` — and ONLY when it is provably closed, so it
 * can no longer be paid anywhere. Listeners of {@see AbandonedCheckouts::HOOK} then hand back what it held.
 *
 * Provably closed, by the gateway the checkout went to (details.checkout_gateway, set before the gateway starts):
 *   stripe        no session yet, or its Checkout session (one-time or subscription) expired at Stripe
 *   paypal        a one-time order: nothing moves until WE capture it, and an abandoned row is never captured (a
 *                 capture already in flight when it was abandoned still settles it: PaypalAdapter, Settlement orAbandoned)
 *   paddle        no transaction yet, or its ready transaction canceled at Paddle
 *   lemon_squeezy its checkout's expires_at has passed by more than Lemon's webhook retries; a checkout row without an
 *                 expires_at (from before it was recorded: such a Lemon checkout never expires) is never closed here
 *   any other     no close API: closed once the gateway's maximum lifetime (LIFETIME_HOURS) has passed
 * A checkout whose gateway could not start (start(): it threw or flashed an error) before any gateway reference was
 * recorded (payment_id, paddle_transaction, lemon_expires_at) is abandoned at once: nothing at the gateway can be paid.
 * A PayPal subscription set-up row and a wire transfer awaiting proof (status pending) are never abandoned here; a
 * reservation they hold is handed back after their lifetime (PromotionRedemption::releaseStale).
 */
final class AbandonedCheckout implements AbandonedCheckouts
{
    /**
     * Lemon Squeezy retries a failed webhook a few times within minutes; a checkout is treated as closed only this long
     * after its expires_at, so an order paid at the last moment still finds its row open.
     */
    public const LEMON_WEBHOOK_MARGIN_MINUTES = 120;

    /**
     * How long a checkout can still be paid at its gateway, in hours — after that it counts as closed (no alert): a
     * hosted payment page has long expired, a wire transfer had two weeks for its proof. Gateways with a close API
     * (Stripe, Paddle) are closed through it; their lifetime only bounds a reservation a refused close keeps.
     */
    private const LIFETIME_HOURS = ['stripe' => 48, 'paddle' => 48, 'paypal' => 72, 'lemon_squeezy' => 24, 'wire_transfer' => 336];

    private const DEFAULT_LIFETIME_HOURS = 24;

    /** Statuses of a checkout row that stands for a settled payment. */
    private const PAID = [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED, Transaction::STATUS_CHECKOUT_SETTLED];

    public function settled(int $checkoutId, ?bool $recurring = null): void
    {
        // One mechanism (BL-038, main): the same marking the MoR gateway controllers apply after a 200 settlement.
        $checkout = Transaction::query()->withoutGlobalScopes()->find($checkoutId);
        if ($checkout !== null) {
            Transaction::markCheckoutSettled($checkoutId, $checkout->user_id, $recurring);
        }
    }

    public function isPaid(Transaction $checkout): bool
    {
        // recurring_setup is a settled first payment on a MoR gateway and on PayPal (BL-039: set when its first sale
        // settles); on Stripe it is only the kept set-up row of an open Checkout session.
        return in_array($checkout->status, self::PAID, true)
            || ($checkout->status === Transaction::STATUS_RECURRING_SETUP && $this->gateway($checkout) !== 'stripe'
                && ! str_starts_with((string) $checkout->payment_id, 'cs_'));
    }

    public function isTerminalUnpaid(Transaction $checkout): bool
    {
        // Still open: no status yet, a wire transfer awaiting its proof, a subscription set-up row not yet paid (a
        // Stripe recurring_setup row, a PayPal subscription awaiting its first sale).
        $open = [null, Transaction::STATUS_PENDING, Transaction::STATUS_RECURRING_SETUP, Transaction::STATUS_RECURRING_PENDING];

        return ! $this->isPaid($checkout) && ! in_array($checkout->status, $open, true);
    }

    public function withinLifetime(Transaction $checkout): bool
    {
        $hours = self::LIFETIME_HOURS[$this->gateway($checkout)] ?? self::DEFAULT_LIFETIME_HOURS;

        return $checkout->created_at === null || $checkout->created_at->copy()->addHours($hours)->isFuture();
    }

    public function hasCloseApi(Transaction $checkout): bool
    {
        return in_array($this->gateway($checkout), ['stripe', 'paddle'], true);
    }

    /**
     * The gateway the checkout went to: recorded before it started (or by the MoR pay()), else known from the checkout
     * the gateway created (rows from before every MoR checkout recorded it), else the one that settled / holds it.
     */
    private function gateway(Transaction $checkout): string
    {
        $details = $checkout->details;

        return (string) (data_get($details, 'checkout_gateway')
            ?: (data_get($details, 'paddle_transaction') !== null ? 'paddle'
                : (data_get($details, 'lemon_expires_at') !== null ? 'lemon_squeezy' : $checkout->transaction_gatway)));
    }

    public function abandon(Transaction $transaction): bool
    {
        if (! $transaction->exists || ! $this->neverSettled($transaction) || ! $this->closed($transaction)) {
            return false;
        }

        return $this->mark($transaction);
    }

    private function mark(Transaction $transaction): bool
    {
        $marked = Transaction::query()->withoutGlobalScopes()->whereKey($transaction->id)
            ->where(fn ($q) => $transaction->status === null ? $q->whereNull('status') : $q->where('status', $transaction->status))
            ->update(['status' => Transaction::STATUS_ABANDONED]);
        if ($marked !== 1) {
            return false; // settled (or abandoned) by a concurrent request
        }
        $transaction->status = Transaction::STATUS_ABANDONED;
        Hooks::call(self::HOOK, $transaction);

        return true;
    }

    public function start(Transaction $transaction, string $gateway, \Closure $pay): mixed
    {
        if (empty($transaction->coupon)) {
            return $pay($transaction);
        }
        $details = (array) ($transaction->details ?? []);
        $details['checkout_gateway'] = $gateway;
        $transaction->update(['details' => $details]);
        session()->forget(['app_alert_message', 'app_alert_type']);
        try {
            $response = $pay($transaction);
        } catch (\Throwable $e) {
            $this->abandonFresh($transaction);

            throw $e;
        }
        if (session('app_alert_type') === 'error') {
            $this->abandonFresh($transaction);
        }

        return $response;
    }

    private function abandonFresh(Transaction $transaction): void
    {
        $fresh = Transaction::query()->withoutGlobalScopes()->find($transaction->id);
        if ($fresh === null) {
            return;
        }
        // BL-038 review: the gateway never got as far as a checkout the buyer can pay — abandoned at once, so its
        // reservation is handed back now (a gateway with no close API would otherwise hold it for its whole lifetime).
        if ($fresh->status === null && ! $this->hasGatewayReference($fresh)) {
            $this->mark($fresh);

            return;
        }
        $this->abandon($fresh);
    }

    /** Whether the gateway recorded a checkout of its own on this row (a session / order / payment id, a Paddle transaction, a Lemon expiry). */
    private function hasGatewayReference(Transaction $transaction): bool
    {
        return trim((string) $transaction->payment_id) !== ''
            || data_get($transaction->details, 'paddle_transaction') !== null
            || data_get($transaction->details, 'lemon_expires_at') !== null;
    }

    /**
     * Only a checkout that never settled: still pending (no status) — or, for Stripe, its kept subscription set-up row
     * whose session Stripe will expire only while unpaid. A settled Paddle / Lemon Squeezy checkout is marked
     * (recurring_setup / checkout_settled) by its settlement and never touched here.
     */
    private function neverSettled(Transaction $transaction): bool
    {
        return $transaction->status === null
            || ($transaction->status === Transaction::STATUS_RECURRING_SETUP && data_get($transaction->details, 'checkout_gateway') === 'stripe');
    }

    /** Close the checkout at its gateway, or prove it closed; false when it may still be paid. */
    private function closed(Transaction $transaction): bool
    {
        $paymentId = (string) $transaction->payment_id;

        return match ($this->gateway($transaction)) {
            'stripe' => ! str_starts_with($paymentId, 'cs_') || (new StripeAdapter)->expireSession($transaction),
            'paypal' => $transaction->status === null,
            'paddle' => $this->paddleCanceled((string) data_get($transaction->details, 'paddle_transaction', '')),
            'lemon_squeezy' => $this->lemonExpired(data_get($transaction->details, 'lemon_expires_at')),
            // No close API: a hosted payment page that outlived its gateway's maximum lifetime is closed (no alert).
            default => ! $this->withinLifetime($transaction),
        };
    }

    private function paddleCanceled(string $paddleTransaction): bool
    {
        if ($paddleTransaction === '') {
            return true; // Paddle never created it
        }
        $base = gateway_is_sandbox(config('settings.paddle_sandbox_mode'), false) ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
        try {
            // Paddle Billing: a draft / ready transaction can be canceled (PATCH status=canceled); a paid one cannot.
            $response = Http::withToken((string) config('settings.paddle_api_key'))->acceptJson()
                ->patch($base.'/transactions/'.rawurlencode($paddleTransaction), ['status' => 'canceled']);

            return $response->successful() && $response->json('data.status') === 'canceled';
        } catch (\Throwable $e) {
            Log::warning('[paddle] checkout '.$paddleTransaction.' not canceled: '.$e->getMessage());

            return false;
        }
    }

    private function lemonExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            // BL-038 review: no expiry recorded — a checkout created before it was sent never expires at Lemon Squeezy,
            // and one whose create answer was lost cannot be told apart: never closed by time (a failed start is
            // abandoned by start() itself; HourlyHousekeeping leaves such a row for a person after its keep window).
            return false;
        }

        return Carbon::parse((string) $expiresAt)->addMinutes(self::LEMON_WEBHOOK_MARGIN_MINUTES)->isPast();
    }
}
