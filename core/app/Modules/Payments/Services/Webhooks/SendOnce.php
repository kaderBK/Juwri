<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Webhooks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P6-158: at-most-once guard for an outbox side effect that cannot be rolled back (email, print). The marker row
 * (`outbox_send_markers`, unique per message type + entity key) is inserted BEFORE the send, outside any handler
 * transaction ({@see TransactionalOutbox} runs send-once handlers unwrapped), so a process killed after the send and
 * before the commit/ack finds the marker on the re-run and sends nothing. A send that throws releases its marker (the
 * retry may send). A marker left in `sending` by a kill is never re-sent: that is the trade (a rare missed email or
 * ticket the owner can re-issue by hand, instead of a duplicate); it is logged and visible in the table.
 */
final class SendOnce
{
    public const TABLE = 'outbox_send_markers';

    /** Claim the send. False when it was already claimed or sent (do not send). */
    public static function claim(string $type, string $key): bool
    {
        $now = Carbon::now();

        return DB::table(self::TABLE)->insertOrIgnore([
            'message_type' => $type,
            'entity_key' => $key,
            'state' => 'sending',
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    public static function confirm(string $type, string $key): void
    {
        DB::table(self::TABLE)->where('message_type', $type)->where('entity_key', $key)
            ->update(['state' => 'sent', 'updated_at' => Carbon::now()]);
    }

    /** The send failed cleanly (it threw): free the claim so a retry can send. */
    public static function release(string $type, string $key): void
    {
        DB::table(self::TABLE)->where('message_type', $type)->where('entity_key', $key)->where('state', 'sending')->delete();
    }

    /** 'sending' | 'sent' | null (never claimed). */
    public static function state(string $type, string $key): ?string
    {
        $state = DB::table(self::TABLE)->where('message_type', $type)->where('entity_key', $key)->value('state');

        return $state === null ? null : (string) $state;
    }

    /**
     * P6-158 review: operator re-issue. Frees a marker a kill left in `sending` (by row id) so the next outbox
     * re-run / scheduled tick sends again. Only `sending` rows: a confirmed `sent` marker is never released.
     */
    public static function releaseStuck(int $id): bool
    {
        return DB::table(self::TABLE)->where('id', $id)->where('state', 'sending')->delete() === 1;
    }

    /**
     * Delete confirmed (`sent`) markers older than $days. Run with the outbox prune (same retention): once the outbox
     * message is gone nothing re-runs the send. Stuck `sending` markers are kept for the operator.
     */
    public static function prune(int $days): int
    {
        return DB::table(self::TABLE)->where('state', 'sent')->where('updated_at', '<', Carbon::now()->subDays($days))->delete();
    }

    /**
     * Run $send once per (type, key). Returns false without calling it when already claimed. An exception from
     * $send releases the claim and is rethrown; a kill leaves the claim in place.
     *
     * @param  callable(): void  $send
     */
    public static function run(string $type, string $key, callable $send): bool
    {
        if (! self::claim($type, $key)) {
            if (self::state($type, $key) === 'sending') {
                Log::warning("[outbox] {$type} {$key}: an earlier send was cut off before it was confirmed; not sending again");
            }

            return false;
        }
        try {
            $send();
        } catch (\Throwable $e) {
            self::release($type, $key);
            throw $e;
        }
        self::confirm($type, $key);

        return true;
    }
}
