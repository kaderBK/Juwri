<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Webhooks;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * J25-7: process each verified gateway webhook event once.
 *
 * A gateway handler calls {@see self::once()} AFTER its own signature / re-fetch verification, with the provider's
 * identity of the event taken from verified content only (a key read from an unverified body could be used to "spend"
 * a genuine future event). The receipt row is claimed with one atomic insert-or-ignore on the unique
 * (provider, event_id) key — MySQL `insert ignore`, PostgreSQL `on conflict do nothing`, SQLite `insert or ignore`:
 *
 *  - first delivery: claim inserted → $process runs → receipt marked processed (or released, below);
 *  - the same event again: no claim → $duplicate (a 200 no-op); a concurrent copy while the first still runs (or
 *    died): 503 + Retry-After for a server-to-server answer (P6-139), so the gateway keeps retrying;
 *  - $process throws or answers with an HTTP status ≥ 400: the claim is deleted (released), so the provider's
 *    retry processes the event again — a failure never turns into a silently dropped payment;
 *  - a claim still "processing" after {@see self::STALE_AFTER_MINUTES} (the worker died) is taken over by the next
 *    delivery with a conditional update, so exactly one retry wins.
 *
 * A null/empty event id (the gateway's payload names nothing to dedupe on) just runs $process: settlement itself is
 * still claimed atomically per transaction downstream (Settlement::claimPaid, credited_at / consumed claims).
 */
final class WebhookReceipts
{
    public const TABLE = 'webhook_receipts';

    public const STALE_AFTER_MINUTES = 10;

    /** Receipts are kept at least this long (J25-7: retention ≥ 7 days). */
    public const MIN_RETENTION_DAYS = 7;

    public const DEFAULT_RETENTION_DAYS = 30;

    private const MAX_EVENT_ID = 191;

    /**
     * @template T
     *
     * @param  Closure(): T  $process  the gateway's handling of the verified event
     * @param  mixed  $duplicate  what to answer for a duplicate (a Closure is called); default: 200 "ok"
     * @return T|mixed
     */
    public static function once(string $provider, int|string|null $eventId, Closure $process, mixed $duplicate = null): mixed
    {
        $provider = self::provider($provider);
        $eventId = self::eventId($eventId);
        if ($eventId === null) {
            return $process();
        }

        $token = self::claim($provider, $eventId);
        if ($token === null) {
            $answer = $duplicate instanceof Closure ? $duplicate() : ($duplicate ?? response('ok', 200));
            // P6-139: an HTTP copy that arrives while the first delivery's claim is still "processing" is NOT answered
            // 2xx. That first process may have been killed (host time cap, OOM, deploy) and its claim only expires after
            // STALE_AFTER_MINUTES; a 2xx makes the gateway stop retrying and the payment change is lost. 503 +
            // Retry-After brings the gateway back later: then it is a processed duplicate (2xx) or a stale claim the
            // retry takes over. Only a server-to-server answer changes (an HTTP response to a non-GET request); a
            // buyer's browser return (GET, or a redirect) and in-process callers (a bool answer) are unchanged.
            $serverToServer = $answer instanceof Response && ! $answer instanceof RedirectResponse
                && ! (app()->bound('request') && request()->isMethod('GET'));
            if ($serverToServer && self::inFlight($provider, $eventId)) {
                Log::info("[webhook] {$provider} event {$eventId} still in flight — asking the gateway to retry");

                return response('in progress, retry later', 503, ['Retry-After' => '60']);
            }
            Log::info("[webhook] duplicate {$provider} event {$eventId} ignored");

            return $answer;
        }

        try {
            $result = $process();
        } catch (\Throwable $e) {
            self::release($provider, $eventId, $token);

            throw $e;
        }

        if ($result instanceof Response && $result->getStatusCode() >= 400) {
            self::release($provider, $eventId, $token);
        } else {
            self::markProcessed($provider, $eventId, $token);
        }

        return $result;
    }

    /**
     * An event id from verified parts ("paid:tr_123"); null when a part is missing or not a scalar, so the event is
     * processed without a receipt rather than under a key that could collide.
     */
    public static function key(mixed ...$parts): ?string
    {
        $out = [];
        foreach ($parts as $part) {
            if (is_bool($part) || ! is_scalar($part) || trim((string) $part) === '') {
                return null;
            }
            $out[] = trim((string) $part);
        }

        return $out === [] ? null : implode(':', $out);
    }

    /**
     * A short fingerprint of the verified request (method, query, raw body). Appended to a gateway's event key so an
     * identical re-delivery is a duplicate while a notification whose signed content differs (another status, amount
     * or currency under the same provider id) is processed as its own event — the safe direction: never drop a
     * notification that says something new (settlement stays claimed per transaction downstream).
     */
    public static function fingerprint(Request $request): string
    {
        $body = $request->getContent();
        if ($body === '') {
            // A form post whose raw body was already consumed (or built in-process): its parsed fields.
            $fields = $request->request->all();
            ksort($fields);
            $body = http_build_query($fields);
        }

        return substr(hash('sha256', $request->getMethod().' '.$request->getQueryString().' '.$body), 0, 16);
    }

    /**
     * Claim (provider, event id): a claim token when this caller may process the event, null when it was already
     * processed or another delivery is processing it right now.
     */
    public static function claim(string $provider, string $eventId): ?string
    {
        $now = Carbon::now();
        $token = Str::random(32);

        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $eventId,
            'status' => 'processing',
            'claim_token' => $token,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 1) {
            return $token;
        }

        // Take over a claim whose worker died mid-way. The condition is re-checked under the row lock, so of two
        // racing retries only one moves claimed_at and wins.
        $taken = DB::table(self::TABLE)
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->where('status', 'processing')
            ->where('claimed_at', '<', $now->copy()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update([
                'claim_token' => $token,
                'claimed_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        return $taken === 1 ? $token : null;
    }

    private static function inFlight(string $provider, string $eventId): bool
    {
        return DB::table(self::TABLE)->where('provider', $provider)->where('event_id', $eventId)
            ->where('status', 'processing')->exists();
    }

    public static function processed(string $provider, int|string $eventId): bool
    {
        return DB::table(self::TABLE)
            ->where('provider', self::provider($provider))
            ->where('event_id', self::eventId($eventId))
            ->where('status', 'processed')
            ->exists();
    }

    /** Delete receipts older than the retention period (never less than {@see self::MIN_RETENTION_DAYS}). */
    public static function prune(?int $days = null): int
    {
        $days = max(self::MIN_RETENTION_DAYS, $days ?? self::DEFAULT_RETENTION_DAYS);

        return DB::table(self::TABLE)->where('created_at', '<', Carbon::now()->subDays($days))->delete();
    }

    private static function markProcessed(string $provider, string $eventId, string $token): void
    {
        $now = Carbon::now();
        DB::table(self::TABLE)
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->where('claim_token', $token)
            ->update(['status' => 'processed', 'processed_at' => $now, 'updated_at' => $now]);
    }

    private static function release(string $provider, string $eventId, string $token): void
    {
        try {
            DB::table(self::TABLE)
                ->where('provider', $provider)
                ->where('event_id', $eventId)
                ->where('claim_token', $token)
                ->where('status', 'processing')
                ->delete();
        } catch (\Throwable $e) {
            // The claim then expires after STALE_AFTER_MINUTES and a retry takes it over.
            Log::warning("[webhook] could not release {$provider} event {$eventId}: ".$e->getMessage());
        }
    }

    private static function provider(string $provider): string
    {
        return substr(strtolower(trim($provider)), 0, 40);
    }

    /** The stored event id: trimmed; a long id is stored as its hash so it always fits the unique key. */
    private static function eventId(int|string|null $eventId): ?string
    {
        $eventId = trim((string) $eventId);
        if ($eventId === '') {
            return null;
        }

        return strlen($eventId) > self::MAX_EVENT_ID - 7 ? 'sha256:'.hash('sha256', $eventId) : $eventId;
    }
}
