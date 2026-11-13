<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Jobs;

use App\Models\WaitlistEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Modules\Reservations\Services\Rwg\RwgWaitlistRtuClientFactory;

/**
 * google-partner Lane D (D4): push a waitlist entry's new Google state (RTU).
 *
 * Dispatched from WaitlistEntry's model observer for source='google' entries
 * only. Dark-guarded: with no Reserve-with-Google creds the observer never
 * dispatches, and even if dispatched this re-checks the kill-switch — so a
 * missing-creds environment does zero HTTP. Fail-soft: an RTU push must never
 * break a waitlist write.
 */
class PushRwgWaitlistRtuJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $entryId,
        public string $googleState,
    ) {}

    public function handle(): void
    {
        // Kill-switch: dark unless the owner wired Reserve-with-Google creds.
        if (! config('services.reserve_with_google.enabled')) {
            return;
        }

        $entry = WaitlistEntry::find($this->entryId);
        if (! $entry || (string) $entry->source !== 'google') {
            return;
        }

        try {
            RwgWaitlistRtuClientFactory::make()->push($entry, $this->googleState);
        } catch (\Throwable $e) {
            // best-effort only — never break the waitlist write that triggered us
        }
    }
}
