<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use App\Models\SellerWithdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Payments\Mail\PaymentHealthAlertMail;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P6-93 — payments health watch (`payments:watch`, every 5 minutes). PaymentLog alerts on single events; this looks
 * at the STATE of the payment tables and raises an alert when something has been wrong for a while:
 *
 *  - stuck: gateway payments with no confirmation after N minutes (per gateway; slow-by-design rails such as bank
 *    transfer are excluded), at least `stuck_min_count` of them;
 *  - webhook_rejections: provider notifications rejected per clock hour (platform-wide and per store);
 *  - refunds: a refund whose outcome is unknown (RefundGuard keeps it `pending` — reconcile by hand) or that the
 *    provider refused recently;
 *  - ledger: ledger:reconcile drift (run at most every `ledger_every_minutes`);
 *  - payouts: seller withdrawals waiting longer than `payout_pending_hours`;
 *  - outbox: relay backlog (oldest owed message older than `outbox_backlog_minutes`, or messages failed for good).
 *
 * Platform findings go to admins through AlertDispatcher. A finding about ONE store also e-mails that store's owner
 * (only that store's rows, never another store's). Every finding has a key; a key alerts at most once per cooldown.
 */
class PaymentsWatch
{
    private const REJECT_KEY = 'payments:webhook_rejected:';

    private const COOLDOWN_KEY = 'payments:watch:sent:';

    /** @var array<int, list<array{0:string,1:array<string,string|int>}>> owner lines per store id */
    private array $ownerLines = [];

    /** Count one rejected provider notification in the current clock hour (best-effort, never throws). */
    public static function countWebhookRejection(?int $storeId): void
    {
        try {
            $hour = now()->format('YmdH');
            foreach (array_filter([self::REJECT_KEY.$hour, $storeId ? self::REJECT_KEY.$hour.':store:'.$storeId : null]) as $key) {
                Cache::add($key, 0, 7200);
                Cache::increment($key);
            }
            if ($storeId) {
                // Which stores had rejections this hour (best-effort set; the counters above are the truth).
                $set = (array) Cache::get(self::REJECT_KEY.$hour.':stores', []);
                if (! in_array($storeId, $set, true)) {
                    $set[] = $storeId;
                    Cache::put(self::REJECT_KEY.$hour.':stores', $set, 7200);
                }
            }
        } catch (\Throwable $e) {
            // counters are best-effort — a rejected webhook must never fail on them
        }
    }

    /**
     * Run every rule; send what is not on cooldown.
     *
     * @return list<string> the platform messages raised this run (sent or on cooldown)
     */
    public function run(): array
    {
        $this->ownerLines = [];
        $messages = [];
        foreach ([$this->stuckPayments(...), $this->webhookRejections(...), $this->refunds(...), $this->ledger(...), $this->payouts(...), $this->outbox(...)] as $rule) {
            foreach ($rule() as $key => $message) {
                $messages[] = $message;
                if ($this->firstInCooldown('admin:'.$key)) {
                    AlertDispatcher::send('payments-watch', $message);
                }
            }
        }
        $this->mailOwners();

        return $messages;
    }

    /** @return array<string, string> */
    private function stuckPayments(): array
    {
        $minutes = (int) $this->cfg('stuck_after_minutes', 30);
        $min = max(1, (int) $this->cfg('stuck_min_count', 3));
        $slow = array_map('strtolower', (array) $this->cfg('slow_gateways', []));
        $rows = Transaction::query()
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->where('created_at', '>=', now()->subHours((int) $this->cfg('stuck_lookback_hours', 24)))
            ->whereNotNull('transaction_gatway')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', Transaction::STATUS_PENDING))
            ->orderBy('id')
            ->get(['store_id', 'transaction_gatway'])
            ->reject(fn (Transaction $t) => in_array(strtolower((string) $t->transaction_gatway), $slow, true));

        $out = [];
        foreach ($rows->groupBy('transaction_gatway') as $gateway => $group) {
            if ($group->count() >= $min) {
                $out['stuck:'.$gateway] = "payments: {$group->count()} {$gateway} payment(s) unconfirmed for over {$minutes} min";
            }
            foreach ($group->whereNotNull('store_id')->groupBy('store_id') as $storeId => $own) {
                if ($own->count() >= $min) {
                    $this->ownerLine((int) $storeId, 'stuck:'.$gateway, ':count payments on :gateway have been waiting for confirmation for over :minutes minutes.', [
                        'count' => $own->count(), 'gateway' => (string) $gateway, 'minutes' => $minutes,
                    ]);
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function webhookRejections(): array
    {
        $max = max(1, (int) $this->cfg('webhook_rejections_per_hour', 20));
        $storeMax = max(1, (int) $this->cfg('webhook_rejections_per_hour_store', 5));
        $out = [];
        foreach ([now(), now()->subHour()] as $at) {
            $hour = $at->format('YmdH');
            $count = (int) Cache::get(self::REJECT_KEY.$hour, 0);
            if ($count >= $max) {
                $out['rejections:'.$hour] = "payments: {$count} webhook notification(s) rejected in hour {$at->format('Y-m-d H')}:00";
            }
        }
        // Per store (the store's own gateway keys): that store's owner only.
        foreach ([now(), now()->subHour()] as $at) {
            $hour = $at->format('YmdH');
            foreach ((array) Cache::get(self::REJECT_KEY.$hour.':stores', []) as $storeId) {
                $count = (int) Cache::get(self::REJECT_KEY.$hour.':store:'.$storeId, 0);
                if ($count >= $storeMax) {
                    $this->ownerLine((int) $storeId, 'rejections:'.$hour, ':count payment notifications for your store were rejected in the last hour. Check your payment gateway keys and webhook settings.', ['count' => $count]);
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function refunds(): array
    {
        $pendingMinutes = (int) $this->cfg('refund_pending_minutes', 15);
        $failedLookback = (int) $this->cfg('refund_failed_lookback_minutes', 60);
        $out = [];
        $rows = DB::table('transaction')->where('details', 'like', '%gateway_refunds%')
            ->where(fn ($q) => $q->where('details', 'like', '%"pending"%')->orWhere('details', 'like', '%"failed"%'))
            // Alert and owner-mail lines in a stable order: without it PostgreSQL returns rows in heap order, which
            // changes when a row is updated (its new version lands later), so the order depended on earlier writes.
            ->orderBy('id')
            ->get(['id', 'store_id', 'invoice_number', 'details']);
        foreach ($rows as $row) {
            $details = json_decode((string) $row->details, true);
            foreach ((array) ($details['gateway_refunds'] ?? []) as $entry) {
                if (! is_array($entry) || ! isset($entry['id'], $entry['at'])) {
                    continue;
                }
                $at = Carbon::parse((string) $entry['at']);
                $status = $entry['status'] ?? null;
                $amount = Money::ofMinor((int) ($entry['amount_minor'] ?? 0), (string) ($entry['currency'] ?? 'USD'))->major().' '.($entry['currency'] ?? '');
                $ref = $row->invoice_number ?: '#'.$row->id;
                $store = $row->store_id ? ' store '.$row->store_id : ' platform';
                if ($status === 'pending' && $at->lte(now()->subMinutes($pendingMinutes))) {
                    $out['refund:'.$entry['id']] = "payments: refund {$amount} on transaction {$row->id} ({$store}, ".($entry['gateway'] ?? '?').') outcome UNKNOWN since '.$at->toDateTimeString().' — reconcile with the provider';
                    if ($row->store_id) {
                        $this->ownerLine((int) $row->store_id, 'refund:'.$entry['id'], 'A refund of :amount on payment :invoice has no confirmed outcome. Check your payment provider dashboard before refunding again.', ['amount' => $amount, 'invoice' => $ref]);
                    }
                } elseif ($status === 'failed' && $at->gte(now()->subMinutes($failedLookback))) {
                    $out['refund:'.$entry['id']] = "payments: refund {$amount} on transaction {$row->id} ({$store}, ".($entry['gateway'] ?? '?').') refused by the provider';
                    if ($row->store_id) {
                        $this->ownerLine((int) $row->store_id, 'refund:'.$entry['id'], 'A refund of :amount on payment :invoice was refused by the payment provider.', ['amount' => $amount, 'invoice' => $ref]);
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function ledger(): array
    {
        $every = max(1, (int) $this->cfg('ledger_every_minutes', 60));
        if (! Cache::add('payments:watch:ledger-ran', 1, $every * 60)) {
            $last = Cache::get('payments:watch:ledger-drift');

            return $last ? ['ledger' => (string) $last] : [];
        }
        $code = Artisan::call('ledger:reconcile');
        $message = null;
        if ($code !== 0) {
            preg_match('/(\d+) issue/', Artisan::output(), $m);
            $message = 'payments: ledger:reconcile found drift ('.($m[1] ?? '?').' issue(s))';
        }
        Cache::put('payments:watch:ledger-drift', $message, $every * 60);

        return $message ? ['ledger' => $message] : [];
    }

    /** @return array<string, string> */
    private function payouts(): array
    {
        $hours = (int) $this->cfg('payout_pending_hours', 72);
        $count = SellerWithdrawal::query()->where('status', 'pending')->where('created_at', '<=', now()->subHours($hours))->count();

        return $count > 0 ? ['payouts' => "payments: {$count} seller withdrawal(s) pending for over {$hours} h"] : [];
    }

    /** @return array<string, string> */
    private function outbox(): array
    {
        $minutes = (int) $this->cfg('outbox_backlog_minutes', 15);
        $out = [];
        $owed = DB::table('outbox_messages')->whereIn('status', ['pending', 'processing'])->where('created_at', '<=', now()->subMinutes($minutes));
        $count = (clone $owed)->count();
        if ($count > 0) {
            $oldest = (string) (clone $owed)->min('created_at');
            $out['outbox:backlog'] = "payments: outbox relay backlog — {$count} message(s) owed for over {$minutes} min (oldest {$oldest})";
        }
        $failed = DB::table('outbox_messages')->where('status', 'failed')->count();
        if ($failed > 0) {
            $out['outbox:failed'] = "payments: {$failed} outbox message(s) failed after all retries — needs an operator";
        }

        return $out;
    }

    /** @param array<string, string|int> $params */
    private function ownerLine(int $storeId, string $key, string $line, array $params): void
    {
        if ($this->firstInCooldown('store:'.$storeId.':'.$key)) {
            $this->ownerLines[$storeId][] = [$line, $params];
        }
    }

    /** One e-mail per store per run, to that store's owner only. */
    private function mailOwners(): void
    {
        foreach ($this->ownerLines as $storeId => $lines) {
            try {
                $store = Store::find($storeId);
                $owner = $store?->user;
                if (! $store || ! $owner || (string) $owner->email === '') {
                    continue;
                }
                $mail = new PaymentHealthAlertMail((string) $owner->name, (string) $store->title, $lines);
                if (! empty($owner->preferred_locale)) {
                    $mail->locale((string) $owner->preferred_locale);
                }
                Mail::to($owner->email)->queue($mail);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function firstInCooldown(string $key): bool
    {
        return Cache::add(self::COOLDOWN_KEY.$key, 1, max(1, (int) $this->cfg('cooldown_minutes', 60)) * 60);
    }

    private function cfg(string $key, mixed $default): mixed
    {
        return config('payments.watch.'.$key, $default);
    }
}
