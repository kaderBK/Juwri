<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Settings;

use Illuminate\Support\Facades\DB;

/**
 * P1-07 slice 5e: typed per-user settings — the one reader/writer that replaces the `user_options`
 * key/value table (19 mapped keys, all of them account/owner preferences):
 *
 *  - `default_store_id` / `default_branch_id`  -> real `users` columns (the saved login default);
 *  - `billing_*` (invoice details)             -> `accounts.billing_profile` (json, keys without the
 *    prefix: name, address, city, state, zipcode, country, details_type, tax_id), or `users.preferences`
 *    for a user that has no account row;
 *  - everything else                           -> `users.preferences` (json, flat name => typed value;
 *    flags become bools, `dashnotif_seen` an int, the JSON documents arrays; an unlisted name is kept
 *    verbatim - nothing is ever dropped).
 *
 * Values are lossless: only '1'/'0' become bools for a flag, only canonical integer text becomes an int, a
 * JSON document becomes an array only when it round-trips exactly, every other value stays the string it
 * was. legacy()/legacyMap() return the pre-P1-07 string form (`'1'`/`'0'`, numbers as text, JSON text), so
 * UserOption::getUserOption() and the user_options() helper answer exactly as they always did.
 *
 * Every access takes an explicit user id: a user never reads or writes another user's settings.
 */
final class UserSettings
{
    /** Names that live in real `users` columns. */
    private const COLUMNS = ['default_store_id', 'default_branch_id'];

    private const BILLING_PREFIX = 'billing_';

    /** Legacy name (minus prefix) of the keys that go to accounts.billing_profile. */
    private const BILLING = ['name', 'address', 'city', 'state', 'zipcode', 'country', 'details_type', 'tax_id'];

    private const BOOLS = [
        'anomaly_alerts_email', 'weekly_digest_email', 'onboarding_dismissed', 'package_trial_done',
        'security_nudge_dismissed', // P6-47: owner dismissed the 2FA/passkey prompt
        'peer_benchmark_opt_in', 'spotlight_trial_emails', 'wallet_auto_renew',
        'marketing_optin', // P1-23: sign-up "product news and tips" opt-in (marketing_optin_at = when)
    ];

    private const INTS = ['dashnotif_seen'];

    private const DOCUMENTS = ['dashboard_hidden_cards', 'dashboard_card_order', 'dashnotif_prefs', 'nav_pins'];

    // ---- reads ----------------------------------------------------------------------------

    /** Pre-P1-07 string form of one setting, null when unset. */
    public static function legacy(int $userId, string $name): ?string
    {
        $value = self::raw($userId, $name);

        return $value === null ? null : self::toLegacy($value);
    }

    public static function has(int $userId, string $name): bool
    {
        return self::raw($userId, $name) !== null;
    }

    /**
     * Every setting of a user as legacy name => legacy string.
     *
     * @return array<string, string>
     */
    public static function legacyMap(int $userId): array
    {
        $user = DB::table('users')->where('id', $userId)->first(['account_id', 'preferences', 'default_store_id', 'default_branch_id']);
        if ($user === null) {
            return [];
        }
        $out = [];
        foreach (self::decode($user->preferences) as $name => $value) {
            if ($value !== null) {
                $out[(string) $name] = self::toLegacy($value);
            }
        }
        foreach (self::COLUMNS as $column) {
            if ($user->{$column} !== null) {
                $out[$column] = (string) $user->{$column};
            }
        }
        if ($user->account_id !== null) {
            foreach (self::decode(DB::table('accounts')->where('id', $user->account_id)->value('billing_profile')) as $key => $value) {
                if ($value !== null) {
                    $out[self::BILLING_PREFIX.$key] = self::toLegacy($value);
                }
            }
        }

        return $out;
    }

    /**
     * Rows shaped like the old `user_options` table (backups / archives).
     *
     * @return list<array{option_name: string, option_value: string}>
     */
    public static function legacyRows(int $userId): array
    {
        $rows = [];
        foreach (self::legacyMap($userId) as $name => $value) {
            $rows[] = ['option_name' => $name, 'option_value' => $value];
        }

        return $rows;
    }

    /**
     * Legacy string value of one setting for every user that has it (or only $userIds).
     * Column-backed and account-backed names are answered too, from their own storage.
     *
     * @param  list<int>|null  $userIds
     * @return array<int, string> user id => legacy value
     */
    public static function valuesFor(string $name, ?array $userIds = null): array
    {
        $out = [];
        if (in_array($name, self::COLUMNS, true)) {
            $query = DB::table('users')->whereNotNull($name)->select(['id', $name]);
            if ($userIds !== null) {
                $query->whereIn('id', $userIds);
            }
            foreach ($query->orderBy('id')->cursor() as $row) {
                $out[(int) $row->id] = (string) $row->{$name};
            }

            return $out;
        }
        $billingKey = self::billingKey($name);
        if ($billingKey !== null) {
            $query = DB::table('users')->join('accounts', 'accounts.id', '=', 'users.account_id')->select(['users.id as uid', 'accounts.billing_profile as profile']);
            if ($userIds !== null) {
                $query->whereIn('users.id', $userIds);
            }
            foreach ($query->orderBy('users.id')->cursor() as $row) {
                $value = self::decode($row->profile)[$billingKey] ?? null;
                if ($value !== null) {
                    $out[(int) $row->uid] = self::toLegacy($value);
                }
            }

            return $out;
        }
        $query = DB::table('users')->whereNotNull('preferences')->select(['id', 'preferences']);
        if ($userIds !== null) {
            $query->whereIn('id', $userIds);
        }
        foreach ($query->orderBy('id')->cursor() as $row) {
            $value = self::decode($row->preferences)[$name] ?? null;
            if ($value !== null) {
                $out[(int) $row->id] = self::toLegacy($value);
            }
        }

        return $out;
    }

    /**
     * @param  callable(string): bool  $test
     * @return list<int>
     */
    public static function userIdsWhere(string $name, callable $test): array
    {
        $ids = [];
        foreach (self::valuesFor($name) as $id => $value) {
            if ($test($value)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // ---- writes ---------------------------------------------------------------------------

    /** Save one setting. Returns false when the user does not exist. */
    public static function set(int $userId, string $name, mixed $value): bool
    {
        return self::setMany($userId, [$name => $value]);
    }

    /**
     * @param  array<string, mixed>  $values  legacy name => value
     */
    public static function setMany(int $userId, array $values): bool
    {
        return self::write($userId, $values, []);
    }

    public static function forget(int $userId, string $name): void
    {
        self::write($userId, [], [$name]);
    }

    /** Remove every setting of a user (preferences, saved defaults and the account's billing details). */
    public static function forgetAll(int $userId): void
    {
        $user = DB::table('users')->where('id', $userId)->first(['account_id']);
        if ($user === null) {
            return;
        }
        DB::table('users')->where('id', $userId)->update(['preferences' => null, 'default_store_id' => null, 'default_branch_id' => null]);
        if ($user->account_id !== null) {
            DB::table('accounts')->where('id', $user->account_id)->update(['billing_profile' => null]);
        }
    }

    // ---- internals ------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $set
     * @param  list<string>  $forget
     */
    private static function write(int $userId, array $set, array $forget): bool
    {
        if (! DB::table('users')->where('id', $userId)->exists()) {
            return false;
        }
        DB::transaction(function () use ($userId, $set, $forget) {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['account_id', 'preferences']);
            $prefs = self::decode($user->preferences);
            $columns = [];
            $profile = null;
            if ($user->account_id !== null) {
                $profile = self::decode(DB::table('accounts')->where('id', $user->account_id)->lockForUpdate()->value('billing_profile'));
            }
            $profileTouched = false;

            foreach ($forget as $name) {
                if (in_array($name, self::COLUMNS, true)) {
                    $columns[$name] = null;
                } elseif (($key = self::billingKey($name)) !== null && $profile !== null) {
                    unset($profile[$key]);
                    $profileTouched = true;
                } else {
                    unset($prefs[$name]);
                }
            }
            foreach ($set as $name => $value) {
                $name = (string) $name;
                if (in_array($name, self::COLUMNS, true)) {
                    $columns[$name] = $value === null || $value === '' ? null : (int) $value;
                } elseif (($key = self::billingKey($name)) !== null && $profile !== null) {
                    $profile[$key] = $value === null ? null : (string) self::toLegacy($value);
                    $profileTouched = true;
                } else {
                    $prefs[$name] = self::coerce($name, $value);
                }
            }

            $update = $columns + ['preferences' => $prefs === [] ? null : json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
            DB::table('users')->where('id', $userId)->update($update);
            if ($profileTouched) {
                $profile = array_filter($profile, fn ($v) => $v !== null);
                DB::table('accounts')->where('id', $user->account_id)->update([
                    'billing_profile' => $profile === [] ? null : json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        });

        return true;
    }

    private static function raw(int $userId, string $name): mixed
    {
        $user = DB::table('users')->where('id', $userId)->first(['account_id', 'preferences', 'default_store_id', 'default_branch_id']);
        if ($user === null) {
            return null;
        }
        if (in_array($name, self::COLUMNS, true)) {
            return $user->{$name};
        }
        if (($key = self::billingKey($name)) !== null && $user->account_id !== null) {
            return self::decode(DB::table('accounts')->where('id', $user->account_id)->value('billing_profile'))[$key] ?? null;
        }

        return self::decode($user->preferences)[$name] ?? null;
    }

    private static function billingKey(string $name): ?string
    {
        if (! str_starts_with($name, self::BILLING_PREFIX)) {
            return null;
        }
        $key = substr($name, strlen(self::BILLING_PREFIX));

        return in_array($key, self::BILLING, true) ? $key : null;
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function coerce(string $name, mixed $value): mixed
    {
        if (in_array($name, self::BOOLS, true)) {
            if ($value === true || $value === 1 || $value === '1') {
                return true;
            }
            if ($value === false || $value === 0 || $value === '0') {
                return false;
            }

            return self::toLegacy($value);
        }
        if (in_array($name, self::INTS, true)) {
            if (is_int($value)) {
                return $value;
            }

            return is_string($value) && preg_match('/^(0|-?[1-9][0-9]{0,17})$/', $value) === 1 ? (int) $value : self::toLegacy($value);
        }
        if (in_array($name, self::DOCUMENTS, true)) {
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded) && array_is_list($decoded) && json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $value) {
                    return $decoded;
                }
                if (is_array($decoded) && ! array_is_list($decoded) && json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $value) {
                    return $decoded;
                }
            }

            return self::toLegacy($value);
        }

        return self::toLegacy($value);
    }

    private static function toLegacy(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
