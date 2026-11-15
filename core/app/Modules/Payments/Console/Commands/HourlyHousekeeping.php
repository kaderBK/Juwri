<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Payments\Contracts\AbandonedCheckouts;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Hourly housekeeping: purge abandoned checkouts, then the `cron.hourly` hooks (Billing: expire lapsed plan
 * upgrades — the user drops back to the free group; P1-13 moved that step into the Billing module).
 */
class HourlyHousekeeping extends Command
{
    /** A checkout that never got a status within this many hours was abandoned. */
    private const ABANDONED_AFTER_HOURS = 3;

    /** A checkout that went to a gateway and never settled is kept this many days (it may still be paid), then purged. */
    private const MOR_KEEP_DAYS = 7;

    /** Hourly attempts to close a stale promotion checkout before it is alerted and left alone (a day). */
    private const CLOSE_ATTEMPTS = 24;

    protected $signature = 'cron:hourly';

    protected $description = 'Hourly housekeeping: abandoned checkouts, lapsed plan upgrades';

    public function handle(): int
    {
        /* BL-038: only never-settled rows (no status) are purged — a settled merchant-of-record checkout is marked
           (recurring_setup / checkout_settled) by its settlement and kept for its renewals. A stale checkout that
           went to a gateway (details.checkout_gateway — every Paddle / Lemon Squeezy checkout, and every one holding
           a promotion) is closed at that gateway and kept as `abandoned` — its reserved use goes back; one that
           cannot be closed yet (a Lemon Squeezy checkout not yet expired, a hosted page within its gateway's
           lifetime) is kept pending and tried again next hour. Rows from before checkout_gateway was recorded on
           every MoR checkout are recognised by their paddle_transaction / lemon_expires_at. Such a checkout may
           still be paid (or its webhook retried) long after 3 hours: it is kept MOR_KEEP_DAYS before it goes —
           except one whose close was refused all day (close_gave_up), which is left for a person. */
        $stale = Transaction::query()->whereNull('status')->where('created_at', '<=', now()->subHours(self::ABANDONED_AFTER_HOURS));
        $atGateway = $stale->clone()->whereNotNull('details')->get()->filter(fn (Transaction $t): bool => $this->wentToGateway($t));
        $atGateway->filter(fn (Transaction $t): bool => data_get($t->details, 'close_gave_up') !== true)
            ->each(fn (Transaction $t) => $this->close($t));
        $stale->clone()->whereKeyNot($atGateway->modelKeys())->delete();

        // Past the keep window: a gateway checkout that never settled (still pending, or provably closed and abandoned) goes.
        $expired = Transaction::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', Transaction::STATUS_ABANDONED))
            ->whereNotNull('details')->where('created_at', '<=', now()->subDays(self::MOR_KEEP_DAYS))->get()
            ->filter(fn (Transaction $t): bool => $this->wentToGateway($t) && data_get($t->details, 'close_gave_up') !== true);
        /* BL-038 review: a pending Lemon Squeezy checkout with no recorded expiry (created before it was sent: it never
           expires at Lemon Squeezy) may still be paid — never deleted; alerted once and left for a person. */
        [$unbounded, $expired] = $expired->partition(fn (Transaction $t): bool => $t->status === null
            && data_get($t->details, 'checkout_gateway') === 'lemon_squeezy' && data_get($t->details, 'lemon_expires_at') === null);
        $unbounded->each(fn (Transaction $t) => $this->leaveForAPerson($t));
        Transaction::query()->whereKey($expired->modelKeys())->delete();

        // BL-039: PayPal subscription checkouts awaiting their first sale are marked `recurring_pending`
        // so the 3-hour purge spares them; drop them once the keep window expires if they never settled.
        Transaction::query()
            ->where('status', Transaction::STATUS_RECURRING_PENDING)
            ->where('created_at', '<=', now()->subDays(self::MOR_KEEP_DAYS))
            ->delete();

        Hooks::call('cron.hourly');

        return self::SUCCESS;
    }

    private function wentToGateway(Transaction $checkout): bool
    {
        return data_get($checkout->details, 'checkout_gateway') !== null
            || data_get($checkout->details, 'paddle_transaction') !== null
            || data_get($checkout->details, 'lemon_expires_at') !== null;
    }

    /** Mark a pending checkout close_gave_up (never purged, never retried) and alert once — unless it settled meanwhile. */
    private function leaveForAPerson(Transaction $checkout): void
    {
        $marked = DB::transaction(function () use ($checkout): bool {
            $fresh = Transaction::query()->withoutGlobalScopes()->lockForUpdate()->find($checkout->id);
            if ($fresh === null || $fresh->status !== null) {
                return false;
            }
            $details = (array) ($fresh->details ?? []);
            $details['close_gave_up'] = true;
            $fresh->forceFill(['details' => $details])->save();

            return true;
        });
        if ($marked) {
            AlertDispatcher::send('payments.checkout_not_closable', 'checkout '.$checkout->id.' (lemon_squeezy) has no expiry and may still be paid: check it by hand');
        }
    }

    /**
     * BL-038: close one stale checkout at its gateway. Only a gateway whose close API refused (a Paddle transaction
     * that will not cancel, a Stripe session that will not expire) counts as an attempt: tried once an hour for a day,
     * then alerted once and left for a person. A gateway without a close API is simply tried again next hour — it
     * counts as closed once its maximum lifetime passed (AbandonedCheckout), with no alert.
     */
    private function close(Transaction $checkout): void
    {
        $checkouts = app(AbandonedCheckouts::class);
        if ($checkouts->abandon($checkout) || ! $checkouts->hasCloseApi($checkout)) {
            return;
        }
        // Atomic: re-read under a row lock, so a webhook writing this row's details at the same moment is not overwritten.
        $alert = DB::transaction(function () use ($checkout): bool {
            $fresh = Transaction::query()->withoutGlobalScopes()->lockForUpdate()->find($checkout->id);
            if ($fresh === null || $fresh->status !== null) {
                return false; // settled or abandoned meanwhile
            }
            $details = (array) ($fresh->details ?? []);
            $details['close_attempts'] = (int) ($details['close_attempts'] ?? 0) + 1;
            $gaveUp = $details['close_attempts'] >= self::CLOSE_ATTEMPTS;
            if ($gaveUp) {
                $details['close_gave_up'] = true;
            }
            $fresh->forceFill(['details' => $details])->save();

            return $gaveUp;
        });
        if ($alert) {
            AlertDispatcher::send('payments.checkout_not_closable', 'checkout '.$checkout->id.' ('.(data_get($checkout->details, 'checkout_gateway') ?? 'paddle').') could not be closed at its gateway: check it by hand');
        }
    }
}
