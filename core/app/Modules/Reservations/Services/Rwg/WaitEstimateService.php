<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

use App\Models\WaitlistEntry;

/**
 * google-partner Lane D (D2): wait estimates for the Reserve-with-Google waitlist
 * booking server (BatchGetWaitEstimates) and the CreateWaitlistEntry quote.
 *
 * Single source of truth for "how long would a party joining now wait", mirroring
 * the position × avg-turn model WaitlistEntry::estimatedWait() and
 * WaitlistController::add() already use — so the Google-facing quote and the
 * dashboard/storefront quote never diverge.
 */
class WaitEstimateService
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    /** add() validation bound — party sizes outside 1..99 are not quotable. */
    private const MIN_PARTY = 1;

    private const MAX_PARTY = 99;

    /**
     * BatchGetWaitEstimates response for a prospective party joining now. A new
     * party sits behind everyone currently 'waiting', so parties_ahead = the live
     * waiting count and the quoted wait = (parties_ahead + 1) × avg-turn.
     *
     * @param  int[]  $partySizes
     * @return array{waitlist_status:string, wait_estimate:list<array{party_size:int, wait_length:array{parties_ahead_count:int, wait_length_seconds:int}}>}
     */
    public function batchEstimate(int $storeId, array $partySizes): array
    {
        $partiesAhead = $this->partiesAhead($storeId);
        $waitSeconds = $this->quotedWaitMinutesFor($storeId) * 60;

        $estimates = [];
        foreach ($this->normalize($partySizes) as $size) {
            $estimates[] = [
                'party_size' => $size,
                'wait_length' => [
                    'parties_ahead_count' => $partiesAhead,
                    'wait_length_seconds' => $waitSeconds,
                ],
            ];
        }

        return [
            'waitlist_status' => $this->statusFor($storeId),
            'wait_estimate' => $estimates,
        ];
    }

    /**
     * Minutes a party joining NOW would be quoted — the exact add()/publicJoin()
     * model: (waiting count + 1) × avg-turn. Reused by CreateWaitlistEntry.
     */
    public function quotedWaitMinutesFor(int $storeId): int
    {
        $avgTurn = (int) store_options($storeId, 'waitlist_avg_turn', 20);
        $position = $this->partiesAhead($storeId) + 1;

        return $position * $avgTurn;
    }

    /** OPEN only when the store opted its RwG waitlist in; else CLOSED. */
    public function statusFor(int $storeId): string
    {
        return store_options($storeId, 'rwg_waitlist_enabled')
            ? self::STATUS_OPEN
            : self::STATUS_CLOSED;
    }

    private function partiesAhead(int $storeId): int
    {
        return WaitlistEntry::forRestaurant($storeId)
            ->where('status', 'waiting')
            ->count();
    }

    /**
     * De-dupe + clamp requested party sizes to the quotable range, preserving the
     * request order (Google sends an array like [2,3,4,...]).
     *
     * @param  int[]  $partySizes
     * @return int[]
     */
    private function normalize(array $partySizes): array
    {
        $seen = [];
        foreach ($partySizes as $s) {
            $s = (int) $s;
            if ($s >= self::MIN_PARTY && $s <= self::MAX_PARTY && ! in_array($s, $seen, true)) {
                $seen[] = $s;
            }
        }

        return $seen;
    }
}
