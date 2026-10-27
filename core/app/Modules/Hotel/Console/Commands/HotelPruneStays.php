<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Console\Commands;

use Juwri\Modules\Hotel\Models\HotelStay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A11 — GDPR-style PII retention for hotel stays. Guest name/phone and the dead
 * guest-folio `token` accumulate on hotel_stays forever; this anonymizes closed
 * stays (checked_out or cancelled) whose record-close time (`checked_out_at`) is
 * older than the retention window (default 180 days): guest_name → 'Guest',
 * guest_phone → null, token → null. The ledger keeps everything statement() needs,
 * so history is preserved — only the PII is scrubbed.
 *
 * Idempotent + scheduled-safe: already-anonymized rows are skipped by the query, so
 * a re-run touches nothing. Wire into the daily scheduler at deploy time.
 */
class HotelPruneStays extends Command
{
    protected $signature = 'hotel:prune-stays {--days=180 : retention window in days}';

    protected $description = 'Anonymize guest PII on closed hotel stays older than the retention window.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = 180;
        }
        $cutoff = Carbon::now()->subDays($days);

        $count = 0;
        HotelStay::query()
            ->whereIn('status', ['checked_out', 'cancelled'])
            ->whereNotNull('checked_out_at')
            ->where('checked_out_at', '<', $cutoff)
            // Skip rows already anonymized so a re-run is a no-op (idempotent).
            ->where(function ($q) {
                $q->where('guest_name', '!=', 'Guest')
                    ->orWhereNotNull('guest_phone')
                    ->orWhereNotNull('token');
            })
            ->chunkById(200, function ($stays) use (&$count) {
                foreach ($stays as $stay) {
                    $stay->guest_name = 'Guest';
                    $stay->guest_phone = null;
                    $stay->token = null;
                    $stay->save();
                    $count++;
                }
            });

        $this->info("hotel:prune-stays anonymized {$count} stay(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
