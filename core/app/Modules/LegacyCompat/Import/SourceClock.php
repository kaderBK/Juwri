<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Carbon\CarbonImmutable;

/**
 * Self-check of the two clock rules (plan Q4, DDL diff §4.5): DATETIME columns are wall times of `source_timezone`,
 * TIMESTAMP columns are instants (read in a UTC session). Both rules hold only if the old application wrote its
 * TIMESTAMPs through a session in its own zone; a server whose zone differs from the app's shifts every TIMESTAMP by
 * that offset.
 *
 * The check: source orders whose DATETIME `created_at` and TIMESTAMP `updated_at` were written together (never
 * updated since) must convert to the same UTC instant. When most such pairs differ by the same whole number of hours,
 * the zones disagree: the import is refused (reported, cut-over waits). Too few pairs = inconclusive (reported, the
 * import goes on).
 */
final class SourceClock
{
    public const TABLE = 'orders';

    public const BLOCKED = 'blocked: source time zone check: TIMESTAMP and DATETIME columns disagree by a whole number of hours (check legacy_compat.import.source_timezone against the source server zone)';

    /** Rows sampled; pairs within this many seconds count as "written together". */
    public const SAMPLE = 500;

    private const TOLERANCE = 2;

    /** Pairs needed for a verdict. */
    private const MIN_PAIRS = 3;

    public function __construct(private readonly ImportContext $ctx) {}

    /** @return bool true when the import may go on */
    public function check(): bool
    {
        $source = $this->ctx->source;
        $report = $this->ctx->report;
        if (! $source->has(self::TABLE)
            || ! in_array('created_at', $source->columns(self::TABLE), true) || ! in_array('updated_at', $source->columns(self::TABLE), true)
            || $source->isInstant(self::TABLE, 'created_at') || ! $source->isInstant(self::TABLE, 'updated_at')) {
            $report->sourceInfo('clock_check', 'not applicable');

            return true;
        }
        $rows = $source->db->table(self::TABLE)->whereNotNull('created_at')->whereNotNull('updated_at')
            ->orderByDesc((string) $source->key(self::TABLE))->limit(self::SAMPLE)->get(['created_at', 'updated_at']);
        /** @var array<int, int> $offsets whole-hour offset => pairs */
        $offsets = [];
        foreach ($rows as $row) {
            $wall = $this->ctx->utc($row->created_at);
            $instant = $this->ctx->utc($row->updated_at, true);
            if ($wall === null || $instant === null) {
                continue;
            }
            $gap = CarbonImmutable::parse($instant, 'UTC')->getTimestamp() - CarbonImmutable::parse($wall, 'UTC')->getTimestamp();
            $hours = (int) round($gap / 3600);
            if ($hours >= -14 && $hours <= 14 && abs($gap - $hours * 3600) <= self::TOLERANCE) {
                $offsets[$hours] = ($offsets[$hours] ?? 0) + 1;
            }
        }
        $pairs = array_sum($offsets);
        if ($pairs < self::MIN_PAIRS) {
            $report->sourceInfo('clock_check', 'inconclusive ('.$pairs.' pairs)');

            return true;
        }
        arsort($offsets);
        $top = (int) array_key_first($offsets);
        $report->sourceInfo('clock_check', 'offset '.$top.' h in '.$offsets[$top].' of '.$pairs.' pairs');
        if ($top !== 0 && $offsets[$top] > ($offsets[0] ?? 0)) {
            $report->conflict(self::BLOCKED, self::TABLE.' offset '.$top.' h');

            return false;
        }

        return true;
    }
}
