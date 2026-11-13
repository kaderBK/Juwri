<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\Request;

/**
 * A5-3 — a capped, client-tunable page size for v1 list endpoints (was hardcoded and
 * inconsistent). `?per_page=` is honoured up to 100; absent/invalid falls back to the
 * endpoint's default.
 */
trait CapsPerPage
{
    protected function perPage(Request $request, int $default, int $max = 100): int
    {
        $requested = (int) $request->query('per_page', $default);

        return max(1, min($max, $requested ?: $default));
    }
}
