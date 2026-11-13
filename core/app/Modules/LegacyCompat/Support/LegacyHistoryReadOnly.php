<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A write to imported history (an order, a payment, a ledger row brought over by the importer) was refused. Rendered
 * as a plain "read-only" answer (409 JSON for the dashboard's fetch calls, a flashed error otherwise), never a 500.
 */
final class LegacyHistoryReadOnly extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = ___('This order was imported from your previous system and is kept as read-only history.');
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'message' => $message], 409);
        }

        return back()->with('error', $message);
    }

    /** Expected refusal, not an application error: nothing to report. */
    public function report(): bool
    {
        return true;
    }

    /** @param list<string> $columns */
    public static function for(string $table, int|string $id, string $action, array $columns = []): self
    {
        $what = $columns === [] ? '' : ' ('.implode(', ', $columns).')';

        return new self("{$table}#{$id} is imported history and read-only: {$action}{$what} refused");
    }
}
