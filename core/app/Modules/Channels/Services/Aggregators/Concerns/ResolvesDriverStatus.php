<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * A6 — shared driver-status resolution for aggregator client factories.
 *
 * Distinguishes three states so callers never silently push against the Fake while
 * believing the real integration is live:
 *   - `real`        driver=real, BYOK creds present, real client class available.
 *   - `unavailable` driver=real, BYOK creds present, but the real client class is
 *                   missing (deferred build) — this is the "fail loud" state.
 *   - `fake`        anything else (the safe default the whole driver is exercisable on).
 */
trait ResolvesDriverStatus
{
    protected static function computeDriverStatus(bool $wantsReal, bool $hasCreds, string $realClass): string
    {
        if ($wantsReal && $hasCreds) {
            return class_exists($realClass) ? 'real' : 'unavailable';
        }

        return 'fake';
    }

    protected static function logDriverUnavailable(string $provider, ?int $storeId): void
    {
        Log::error('aggregator driver=real but real client missing', [
            'provider' => $provider,
            'store_id' => $storeId,
        ]);
    }
}
