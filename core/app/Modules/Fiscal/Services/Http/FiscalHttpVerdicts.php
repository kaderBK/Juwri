<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Http;

/**
 * P6-157: one table that says what an authority's HTTP status means for a submission.
 * A sandbox surprise (e.g. ZATCA answers 409 differently) is a one-line change here; every row has a test
 * in tests/Modules/Fiscal/FiscalHttpVerdictTableTest.php (fixture fiscal-http-verdicts.json).
 *
 * Lookup order: exact status, then class ('2xx', '4xx', '5xx'), then 'other' (1xx/3xx).
 *   ACCEPTED = the authority holds the document (no re-send).
 *   REJECTED = a final "no" (validation); the document needs a correction.
 *   RETRY    = not a verdict (outage, auth, rate limit, duplicate window); the client throws, the job retries same UUID.
 *   BODY     = the answer's body decides (client-specific status field).
 */
final class FiscalHttpVerdicts
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const RETRY = 'retry';

    public const BODY = 'body';

    /** @var array<string, array<int|string, string>> */
    public const TABLE = [
        // ZATCA reporting/clearance. 409 = already reported/cleared (same UUID + hash), see KILLED-JOBS.md / P6-143.
        'zatca' => [
            '2xx' => self::BODY,
            400 => self::REJECTED,
            409 => self::ACCEPTED,
            '4xx' => self::RETRY,
            '5xx' => self::RETRY,
            'other' => self::RETRY,
        ],
        // ETA receipt submissions. 422 = duplicate submission inside ETA's window: retry, never "accepted" blind.
        'eta' => [
            '2xx' => self::BODY,
            401 => self::RETRY,
            403 => self::RETRY,
            408 => self::RETRY,
            409 => self::RETRY,
            422 => self::RETRY,
            429 => self::RETRY,
            '4xx' => self::REJECTED,
            '5xx' => self::RETRY,
            'other' => self::REJECTED,
        ],
        // JoFotara invoices. Other 4xx carry EINV_STATUS / errors in the body.
        'jofotara' => [
            '2xx' => self::BODY,
            401 => self::RETRY,
            403 => self::RETRY,
            408 => self::RETRY,
            409 => self::RETRY,
            429 => self::RETRY,
            '4xx' => self::BODY,
            '5xx' => self::RETRY,
            'other' => self::BODY,
        ],
    ];

    public static function for(string $authority, int $status): string
    {
        $row = self::TABLE[$authority] ?? throw new \InvalidArgumentException('Unknown fiscal authority: '.$authority);
        $class = match (intdiv($status, 100)) {
            2 => '2xx',
            4 => '4xx',
            5 => '5xx',
            default => 'other',
        };

        return $row[$status] ?? $row[$class]; // every row has 2xx / 4xx / 5xx / other
    }
}
