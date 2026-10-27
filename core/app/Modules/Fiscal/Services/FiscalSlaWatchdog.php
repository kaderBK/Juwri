<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * TAX-RUN F0-P2: SLA watchdog. Authorities that allow report-after-print give a
 * 24h window (ZATCA simplified, ETA). This scans for documents that were queued
 * but not yet accepted/rejected past a warning threshold and surfaces them to
 * the owner + an admin metric so a stuck submission never silently blows the SLA.
 */
class FiscalSlaWatchdog
{
    /** Warn this many hours after queueing (before the 24h hard SLA). */
    public const WARN_AFTER_HOURS = 20;

    /**
     * Documents still awaiting authority acceptance past the warning threshold.
     */
    public function overdue(int $warnAfterHours = self::WARN_AFTER_HOURS): Collection
    {
        $cutoff = now()->subHours($warnAfterHours);

        return FiscalDocument::whereIn('status', ['queued', 'submitted'])
            ->whereNotNull('queued_at')
            ->where('queued_at', '<=', $cutoff)
            ->get();
    }

    /**
     * Run the watchdog: flag overdue docs (records a breach marker in the
     * authority_response audit blob) and return the count. Owner/admin
     * notification hooks read the same set.
     */
    public function run(int $warnAfterHours = self::WARN_AFTER_HOURS): int
    {
        $overdue = $this->overdue($warnAfterHours);

        foreach ($overdue as $doc) {
            $resp = $doc->authority_response ?? [];
            $resp['sla_warning'] = true;
            $resp['sla_warned_at'] = now()->toIso8601String();
            $doc->update(['authority_response' => $resp]);

            Log::warning('fiscal.sla.overdue', [
                'document_id' => $doc->id,
                'store_id' => $doc->store_id,
                'country' => $doc->country,
                'queued_at' => optional($doc->queued_at)->toIso8601String(),
            ]);
        }

        return $overdue->count();
    }
}
