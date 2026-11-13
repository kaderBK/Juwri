<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Webhooks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Payments\Contracts\Outbox;

/**
 * J25-7: the `outbox_messages` table behind {@see Outbox}.
 *
 * Each message is claimed with a conditional update (pending → processing) before its handler runs, so two relays
 * (the in-request one and the scheduled one) never perform the same message at the same time. A handler that throws
 * puts its message back to pending with an exponential back-off (1, 2, 4 … 60 minutes); after
 * {@see self::MAX_ATTEMPTS} it is marked failed and kept for an operator (never deleted). A message stuck in
 * processing (the relay died) is due again after {@see self::STALE_AFTER_MINUTES}. A type with no handler
 * registered stays pending (the module that handles it may not be booted in this process).
 */
final class TransactionalOutbox implements Outbox
{
    public const TABLE = 'outbox_messages';

    public const MAX_ATTEMPTS = 10;

    public const STALE_AFTER_MINUTES = 10;

    /** @var array<string, callable(array<string, mixed>): void> */
    private array $handlers = [];

    /** @var array<string, true> types whose handler guards an unrollbackable send with SendOnce (P6-158) */
    private array $sendOnce = [];

    public function record(string $type, array $payload, ?string $dedupeKey = null): ?int
    {
        $now = Carbon::now();
        $row = [
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'dedupe_key' => $dedupeKey,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($dedupeKey === null) {
            return (int) DB::table(self::TABLE)->insertGetId($row);
        }
        if (DB::table(self::TABLE)->insertOrIgnore($row) === 1) {
            return (int) DB::table(self::TABLE)->where('dedupe_key', $dedupeKey)->value('id');
        }

        // Already recorded. Still owed (pending, or failed after MAX_ATTEMPTS): the new intent makes it due now and
        // hands its id back, so the caller relays it inline instead of leaving it to the scheduled relay (a provider
        // retry after a failed inline credit). Done, or being performed by another relay right now: nothing to do.
        // The update's row count is not used: MySQL reports changed rows, not matched ones, so a message already
        // pending and due this second counts 0 there. The owed message is the one that is pending after the update
        // (a relay that claimed it in between has moved it to processing → null, it is being performed).
        DB::table(self::TABLE)
            ->where('dedupe_key', $dedupeKey)
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'pending', 'available_at' => $now, 'updated_at' => $now]);

        $owed = DB::table(self::TABLE)->where('dedupe_key', $dedupeKey)->where('status', 'pending')->value('id');

        return $owed === null ? null : (int) $owed;
    }

    public function relayAfterCommit(array $ids): void
    {
        if ($ids !== []) {
            DB::afterCommit(fn () => $this->relay($ids));
        }
    }

    public function status(int $id): ?string
    {
        $status = DB::table(self::TABLE)->where('id', $id)->value('status');

        return $status === null ? null : (string) $status;
    }

    public function lastError(int $id): ?string
    {
        $error = DB::table(self::TABLE)->where('id', $id)->value('last_error');

        return $error === null ? null : (string) $error;
    }

    public function sendOnce(string $type, string $key, callable $send): bool
    {
        return SendOnce::run($type, $key, $send);
    }

    public function handle(string $type, callable $handler, bool $sendOnce = false): void
    {
        $this->handlers[$type] = $handler;
        unset($this->sendOnce[$type]);
        if ($sendOnce) {
            $this->sendOnce[$type] = true;
        }
    }

    public function relay(?array $ids = null, int $limit = 100): int
    {
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0));
            if ($ids === []) {
                return 0;
            }
        }

        $now = Carbon::now();
        $due = DB::table(self::TABLE)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('status', 'pending')->where('available_at', '<=', $now))
                ->orWhere(fn ($p) => $p->where('status', 'processing')->where('claimed_at', '<', $now->copy()->subMinutes(self::STALE_AFTER_MINUTES))))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'type', 'payload', 'status', 'claimed_at', 'attempts']);

        $done = 0;
        foreach ($due as $message) {
            if (! isset($this->handlers[$message->type])) {
                continue;
            }
            if (! $this->claim($message, $now)) {
                continue; // another relay has it
            }
            $done += $this->perform($message) ? 1 : 0;
        }

        return $done;
    }

    private function claim(object $message, Carbon $now): bool
    {
        return DB::table(self::TABLE)
            ->where('id', $message->id)
            ->where('status', $message->status)
            ->where(fn ($q) => $message->claimed_at === null ? $q->whereNull('claimed_at') : $q->where('claimed_at', $message->claimed_at))
            ->update([
                'status' => 'processing',
                'claimed_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]) === 1;
    }

    private function perform(object $message): bool
    {
        try {
            $payload = json_decode((string) $message->payload, true, 512, JSON_THROW_ON_ERROR);
            // Own transaction (a savepoint when the caller is in one): a DB error in the handler rolls back only
            // the handler's writes and leaves an outer PostgreSQL transaction usable.
            // P6-139: the done mark commits WITH the handler's writes. Before, it was a separate write after the
            // commit, so a process killed between the two (a shared host reaping the cron drain) left the message
            // "processing" and the stale takeover ran the handler a second time.
            $markDone = function () use ($message) {
                $now = Carbon::now();
                DB::table(self::TABLE)->where('id', $message->id)->update([
                    'status' => 'done',
                    'processed_at' => $now,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);
            };
            if (isset($this->sendOnce[$message->type])) {
                // P6-158: an email / print cannot be rolled back, so no wrapping transaction: the handler claims its
                // SendOnce marker (autocommit) before sending. A kill after the send leaves the marker; the re-run
                // finds it and sends nothing. Money handlers never take this branch.
                ($this->handlers[$message->type])(is_array($payload) ? $payload : []);
                $markDone();
            } else {
                DB::transaction(function () use ($message, $payload, $markDone) {
                    ($this->handlers[$message->type])(is_array($payload) ? $payload : []);
                    $markDone();
                });
            }
        } catch (\Throwable $e) {
            $attempts = (int) $message->attempts + 1;
            $failed = $attempts >= self::MAX_ATTEMPTS;
            DB::table(self::TABLE)->where('id', $message->id)->update([
                'status' => $failed ? 'failed' : 'pending',
                'available_at' => Carbon::now()->addMinutes(min(60, 2 ** ($attempts - 1))),
                'last_error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 2000),
                'updated_at' => Carbon::now(),
            ]);
            Log::warning("[outbox] {$message->type} #{$message->id} failed (attempt {$attempts}): ".$e->getMessage());
            report($e);

            return false;
        }

        return true;
    }

    /** Delete performed messages older than $days (pending and failed ones are always kept). */
    public static function prune(int $days): int
    {
        return DB::table(self::TABLE)
            ->where('status', 'done')
            ->where('processed_at', '<', Carbon::now()->subDays(max(WebhookReceipts::MIN_RETENTION_DAYS, $days)))
            ->delete();
    }
}
