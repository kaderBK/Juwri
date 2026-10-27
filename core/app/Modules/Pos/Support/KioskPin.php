<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Support;

use App\Models\StoreOption;
use Illuminate\Support\Facades\Hash;

/**
 * P6-88: the kiosk exit / setup PINs are stored hash-only (bcrypt in the store setting). A value saved before this
 * change is still plaintext: it is compared in constant time and, on a match, rehashed in place (lazy migration);
 * `staff:hash-legacy-pins` hashes the rest. A PIN is never logged or echoed back to the owner form.
 */
final class KioskPin
{
    public const KEYS = ['kiosk_exit_pin', 'kiosk_setup_pin'];

    /** Internal option keys (strength markers) the owner form must not echo. */
    public const RULE_KEYS = ['kiosk_exit_pin_rules', 'kiosk_setup_pin_rules'];

    public static function isHashed(string $stored): bool
    {
        return $stored !== '' && password_get_info($stored)['algo'] !== null;
    }

    public static function stored(int $storeId, string $key): string
    {
        return (string) store_options($storeId, $key);
    }

    public static function isSet(int $storeId, string $key): bool
    {
        return self::stored($storeId, $key) !== '';
    }

    /** P6-242: new PINs need at least this many digits. */
    public const MIN_DIGITS = 6;

    /**
     * Why $pin is not acceptable as a new kiosk PIN ('format' | 'trivial'), or null when it is. Digits only,
     * MIN_DIGITS..12 long, and not one repeated digit, a straight run (123456 / 654321) or a repeated pair /
     * triple (121212, 123123). Wrong guesses are already locked out per device and per store (P3-06).
     */
    public static function problem(string $pin): ?string
    {
        if (! preg_match('/^\d{'.self::MIN_DIGITS.',12}$/', $pin)) {
            return 'format';
        }
        $n = strlen($pin);
        $up = $down = true;
        for ($i = 1; $i < $n; $i++) {
            $d = (int) $pin[$i] - (int) $pin[$i - 1];
            $up = $up && ($d === 1 || $d === -9);
            $down = $down && ($d === -1 || $d === 9);
        }
        if ($up || $down || preg_match('/^(\d{1,3})\1+$/', $pin)) {
            return 'trivial';
        }

        return null;
    }

    /** Marker key: '2' when the stored PIN was set (or proven on use) under the P6-242 rules. */
    private static function ruleKey(string $key): string
    {
        return $key.'_rules';
    }

    /** P6-242: a PIN is stored but was set before the strength rules (or does not meet them) — the owner should re-set it. */
    public static function isWeak(int $storeId, string $key): bool
    {
        return self::isSet($storeId, $key) && (string) store_options($storeId, self::ruleKey($key)) !== '2';
    }

    public static function set(int $storeId, string $key, string $pin): void
    {
        StoreOption::updateStoreOption($storeId, $key, $pin === '' ? '' : Hash::make($pin));
        StoreOption::updateStoreOption($storeId, self::ruleKey($key), $pin !== '' && self::problem($pin) === null ? '2' : '');
    }

    public static function verify(int $storeId, string $key, string $input): bool
    {
        $stored = self::stored($storeId, $key);
        if ($stored === '' || $input === '') {
            return false;
        }
        if (self::isHashed($stored)) {
            $ok = Hash::check($input, $stored);
            if ($ok && self::isWeak($storeId, $key) && self::problem($input) === null) {
                StoreOption::updateStoreOption($storeId, self::ruleKey($key), '2'); // an old PIN that meets the rules
            }

            return $ok;
        }
        if (! hash_equals($stored, $input)) {
            return false;
        }
        self::set($storeId, $key, $input); // legacy plaintext: hash it now

        return true;
    }

    /** Hash a remaining plaintext value; true when it was (or, on a dry run, would be) changed. */
    public static function migrate(int $storeId, string $key, bool $dryRun = false): bool
    {
        $stored = self::stored($storeId, $key);
        if ($stored === '' || self::isHashed($stored)) {
            return false;
        }
        if (! $dryRun) {
            self::set($storeId, $key, $stored);
        }

        return true;
    }
}
