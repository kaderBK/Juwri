<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Console\Commands;

use App\Models\StaffMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Juwri\Modules\Pos\Support\KioskPin;

/**
 * S5 / P6-88 — make every PIN hash-only. Staff rows still holding a plaintext `pin` get a bcrypt pin_hash (when none is
 * set yet) plus the offline verifier, and the plaintext is nulled; kiosk exit/setup PINs still stored in plaintext are
 * rehashed. Idempotent (a second run finds nothing); --dry-run only counts. Never prints a PIN.
 *
 * The `pin` column itself stays (nullable, always null after this) — see P6-88 in the ledger for why it is not dropped.
 */
class HashStaffPins extends Command
{
    protected $signature = 'staff:hash-legacy-pins {--dry-run : Count only, change nothing} {--chunk=500}';

    /** @var array<int, string> */
    protected $aliases = ['staff:hash-pins'];

    protected $description = 'Hash every remaining plaintext staff and kiosk PIN and null the plaintext';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $staffCount = 0;

        StaffMember::query()
            ->whereNotNull('pin')
            ->where('pin', '!=', '')
            ->chunkById($chunk, function ($rows) use (&$staffCount, $dry) {
                foreach ($rows as $staff) {
                    $staffCount++;
                    if ($dry) {
                        continue;
                    }
                    $plain = (string) $staff->pin;
                    if (empty($staff->pin_hash)) { // a set pin_hash is newer than the stale plaintext: keep it
                        $staff->forceFill(['pin_hash' => Hash::make($plain)]);
                        $staff->rememberOfflinePin($plain);
                    }
                    $staff->forceFill(['pin' => null])->save();
                }
            });

        $kioskCount = 0;
        DB::table('stores')->whereNotNull('settings')->where('settings', 'like', '%_pin%')->select('id')
            ->chunkById($chunk, function ($stores) use (&$kioskCount, $dry) {
                foreach ($stores as $store) {
                    foreach (KioskPin::KEYS as $key) {
                        $kioskCount += KioskPin::migrate((int) $store->id, $key, $dry) ? 1 : 0;
                    }
                }
            });

        $verb = $dry ? 'Would hash' : 'Hashed';
        $this->info("{$verb} {$staffCount} staff PIN(s) and {$kioskCount} kiosk PIN(s).");

        return self::SUCCESS;
    }
}
