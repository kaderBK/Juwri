<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Settings;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Support\RequestEpoch;

/**
 * P1-07: typed per-store settings — the one reader/writer of `stores.settings` (json), which replaces
 * the `store_options` key/value rows for every non-gateway setting. Payment gateway names live in
 * `store_payment_gateways` (StorePaymentGateways); StoreOption routes them there before it gets here.
 *
 * Layout: `{"<group>": {"<name>": <typed value>}}` following StoreSettingKeys (`whatsapp.provider`,
 * `qr.padding`, ...). A name the registry does not know is kept as given under `custom.<name>` —
 * nothing is ever dropped.
 *
 * Values are lenient and lossless: only '1'/'0' (and PHP bools/0/1) become bools for a flag, only
 * canonical integer text ("42", never "007" or "+966...") becomes an int, and JSON documents become
 * arrays only when that round-trips exactly; anything else is kept as given. legacy()/legacyMap()
 * return the pre-P1-07 string form ('1'/'0', numbers as text, JSON text) so StoreOption and
 * store_options() keep returning exactly what they always returned.
 *
 * Every access takes an explicit store id: a store never reads or writes another store's settings.
 *
 * `stores.settings` is the only store of these values: the transitional `store_options` mirror and table
 * were removed in P1-07 slice 5.
 */
final class StoreSettings
{
    /** Group holding names the registry does not list. */
    public const CUSTOM = 'custom';

    /** @var array<int, array<string, array<string, mixed>>> store id => decoded settings */
    private static array $memo = [];

    private static ?int $memoApp = null;

    /** @var array<string, string>|null "group.key" => legacy name */
    private static ?array $reverse = null;

    // ---- typed reads ----------------------------------------------------------------------

    /** Typed value, or $default when unset / null. */
    public static function get(int $storeId, string $name, mixed $default = null): mixed
    {
        $value = self::raw($storeId, $name);

        return $value === null ? $default : $value;
    }

    public static function bool(int $storeId, string $name, bool $default = false): bool
    {
        $value = self::raw($storeId, $name);
        if ($value === null) {
            return $default;
        }

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(int $storeId, string $name, int $default = 0): int
    {
        $value = self::raw($storeId, $name);
        if (is_bool($value)) {
            // A numeric setting saved as 1/0 while the registry typed it 'bool' (Phase 3 F2): same legacy form.
            return (int) $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(int $storeId, string $name, string $default = ''): string
    {
        $value = self::raw($storeId, $name);
        if ($value === null) {
            return $default;
        }

        return self::toLegacy($value) ?? $default;
    }

    /**
     * @param  array<array-key, mixed>  $default
     * @return array<array-key, mixed>
     */
    public static function array(int $storeId, string $name, array $default = []): array
    {
        $value = self::raw($storeId, $name);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && json_validate($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $default;
        }

        return $default;
    }

    /** True when a value (even an empty one) is stored for $name. */
    public static function has(int $storeId, string $name): bool
    {
        return self::raw($storeId, $name) !== null;
    }

    /**
     * The whole settings tree of a store (typed).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(int $storeId): array
    {
        self::scopeMemo();

        return self::$memo[$storeId] ??= self::load($storeId);
    }

    // ---- legacy string form (StoreOption / store_options()) ---------------------------------

    /** The pre-P1-07 string form of one setting, null when unset. */
    public static function legacy(int $storeId, string $name): ?string
    {
        $value = self::raw($storeId, $name);

        return $value === null ? null : self::toLegacy($value);
    }

    /**
     * Every setting of a store as legacy name => legacy string (unset/null values left out).
     *
     * @return array<string, string>
     */
    public static function legacyMap(int $storeId): array
    {
        $out = [];
        foreach (self::all($storeId) as $group => $items) {
            foreach ((array) $items as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $name = $group === self::CUSTOM ? (string) $key : (self::reverse()[$group.'.'.$key] ?? null);
                if ($name === null) {
                    continue;
                }
                $out[$name] = (string) self::toLegacy($value);
            }
        }

        return $out;
    }

    /**
     * Rows shaped like the old `store_options` table, for backups / archives / clones.
     *
     * @return list<array{option_name: string, option_value: string}>
     */
    public static function legacyRows(int $storeId): array
    {
        $rows = [];
        foreach (self::legacyMap($storeId) as $name => $value) {
            $rows[] = ['option_name' => $name, 'option_value' => $value];
        }

        return $rows;
    }

    // ---- writes ---------------------------------------------------------------------------

    /** Save one setting (typed per StoreSettingKeys). Returns false when the store does not exist. */
    public static function set(int $storeId, string $name, mixed $value): bool
    {
        return self::setMany($storeId, [$name => $value]);
    }

    /**
     * Save several settings in one transaction.
     *
     * @param  array<string, mixed>  $values  legacy name => value
     */
    public static function setMany(int $storeId, array $values): bool
    {
        return self::write($storeId, $values, []);
    }

    /** Remove one setting. */
    public static function forget(int $storeId, string $name): void
    {
        self::write($storeId, [], [$name]);
    }

    /** @param  list<string>  $names */
    public static function forgetMany(int $storeId, array $names): void
    {
        self::write($storeId, [], $names);
    }

    /**
     * Remove every setting of a store (the caller decides which names survive by re-setting them).
     */
    public static function forgetAll(int $storeId): void
    {
        self::mutate($storeId, function () {
            return [];
        });
        self::flush($storeId);
    }

    /**
     * Atomically add $delta to a numeric setting (wallet balances and other counters): the read,
     * add and write happen under a row lock so concurrent writers never lose an update.
     */
    public static function increment(int $storeId, string $name, float $delta): void
    {
        if (! self::storeExists($storeId)) {
            return;
        }
        self::mutate($storeId, function (array $settings) use ($name, $delta) {
            [$group, $key] = self::locate($name);
            $current = $settings[$group][$key] ?? 0;
            $sum = (is_numeric($current) ? (float) $current : 0.0) + $delta;
            // Same numeric text the SQL `COALESCE(x,0) + n` produced: integers stay integers.
            $newValue = floor($sum) === $sum && abs($sum) < 1e15 ? (int) $sum : $sum;
            $settings[$group][$key] = self::coerce($name, $newValue);

            return $settings;
        });
        self::flush($storeId);
    }

    /** Drop the per-request memo (one store, or all). */
    public static function flush(?int $storeId = null): void
    {
        SettingsVersion::bump();
        if ($storeId === null) {
            self::$memo = [];
        } else {
            unset(self::$memo[$storeId]);
        }
    }

    /** Changes whenever any store's settings were written or the memo was flushed. */
    public static function version(): int
    {
        self::scopeMemo(); // stamp only after this unit of work's scope ran, so the scope's own bump is never missed

        return SettingsVersion::current();
    }

    // ---- cross-store reads (admin / cron scans) --------------------------------------------

    /**
     * Legacy string value of one setting for every store that has it (or only $storeIds).
     *
     * @param  list<int>|null  $storeIds
     * @return array<int, string> store id => legacy value
     */
    public static function valuesFor(string $name, ?array $storeIds = null): array
    {
        [$group, $key] = self::locate($name);
        $out = [];
        $query = DB::table('stores')->whereNotNull('settings')->select(['id', 'settings']);
        if ($storeIds !== null) {
            $query->whereIn('id', $storeIds);
        }
        foreach ($query->orderBy('id')->cursor() as $row) {
            $decoded = json_decode((string) $row->settings, true);
            $value = is_array($decoded) ? ($decoded[$group][$key] ?? null) : null;
            if ($value !== null) {
                $out[(int) $row->id] = (string) self::toLegacy($value);
            }
        }

        return $out;
    }

    /**
     * Ids of the stores whose legacy value of $name satisfies $test.
     *
     * @param  callable(string): bool  $test
     * @return list<int>
     */
    public static function storeIdsWhere(string $name, callable $test): array
    {
        $ids = [];
        foreach (self::valuesFor($name) as $id => $value) {
            if ($test($value)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * P1-21: a settings tree without the given legacy names (pure — nothing is written). Used by the store-data export
     * to leave the owner's connection settings out of a bundle.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    public static function without(array $settings, array $names): array
    {
        foreach ($names as $name) {
            [$group, $key] = self::locate($name);
            if (is_array($settings[$group] ?? null)) {
                unset($settings[$group][$key]);
                if ($settings[$group] === []) {
                    unset($settings[$group]);
                }
            }
        }

        return $settings;
    }

    // ---- internals ------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $set
     * @param  list<string>  $forget
     */
    private static function write(int $storeId, array $set, array $forget): bool
    {
        if (! self::storeExists($storeId)) {
            return false;
        }
        self::mutate($storeId, function (array $settings) use ($set, $forget) {
            foreach ($forget as $name) {
                [$group, $key] = self::locate($name);
                unset($settings[$group][$key]);
                if (isset($settings[$group]) && $settings[$group] === []) {
                    unset($settings[$group]);
                }
            }
            foreach ($set as $name => $value) {
                [$group, $key] = self::locate((string) $name);
                $settings[$group][$key] = self::coerce((string) $name, $value);
            }

            return $settings;
        });
        self::flush($storeId);

        return true;
    }

    /** Read-modify-write of the settings column under a row lock. */
    private static function mutate(int $storeId, callable $change): void
    {
        DB::transaction(function () use ($storeId, $change) {
            $raw = DB::table('stores')->where('id', $storeId)->lockForUpdate()->value('settings');
            $current = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $next = $change(is_array($current) ? $current : []);
            DB::table('stores')->where('id', $storeId)->update([
                'settings' => $next === [] ? null : json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            ]);
        });
    }

    private static function storeExists(int $storeId): bool
    {
        return DB::table('stores')->where('id', $storeId)->exists();
    }

    private static function raw(int $storeId, string $name): mixed
    {
        [$group, $key] = self::locate($name);
        $items = self::all($storeId)[$group] ?? null;

        return is_array($items) ? ($items[$key] ?? null) : null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function load(int $storeId): array
    {
        $raw = DB::table('stores')->where('id', $storeId)->value('settings');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0: string, 1: string} [group, key] for a legacy name */
    private static function locate(string $name): array
    {
        $entry = StoreSettingKeys::lookup($name);
        if ($entry === null) {
            return [self::CUSTOM, $name];
        }
        [$group, $key] = explode('.', $entry[0], 2);

        return [$group, $key];
    }

    /** @return array<string, string> */
    private static function reverse(): array
    {
        if (self::$reverse === null) {
            self::$reverse = [];
            foreach (StoreSettingKeys::KEYS as $name => [$path]) {
                self::$reverse[$path] = $name;
            }
        }

        return self::$reverse;
    }

    private static function scopeMemo(): void
    {
        $app = RequestEpoch::id();
        if (self::$memoApp !== $app) {
            self::$memoApp = $app;
            self::$memo = [];
            self::$reverse = null;
            // The version is never reset (SettingsVersion): restarting at 0 let a later write land on a number
            // StoreOption had already stamped and keep a stale memo (store_currency() stayed on the platform fallback).
            SettingsVersion::bump();
        }
    }

    /** Typed value to store for a legacy name (lenient: an ill-fitting value is kept as text). */
    private static function coerce(string $name, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $type = StoreSettingKeys::lookup($name)[1] ?? 'string';
        $typed = match ($type) {
            'bool' => self::toBool($value),
            'int' => self::toInt($value),
            'array' => self::toArray($value),
            default => self::toText($value),
        };

        return $typed;
    }

    private static function toBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if ($value === '1' || $value === '0') {
            return $value === '1';
        }

        return self::toText($value);
    }

    private static function toInt(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            // Legacy form '1'/'0' either way; an int keeps a numeric setting typed (Phase 3 F2).
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^(0|-?[1-9]\d{0,17})$/', $value)) {
            return (int) $value;
        }

        return self::toText($value);
    }

    private static function toArray(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = json_decode((string) json_encode($value), true);
        }
        if (is_array($value)) {
            return $value;
        }
        $text = self::toText($value);
        if (is_string($text) && json_validate($text)) {
            $trimmed = ltrim($text);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $asArray = json_decode($text, true);
                // Only a document that survives the round trip becomes an array; `{}` and objects
                // with list-like keys ({"0":..,"1":..}) stay as text so their legacy form is exact.
                if (is_array($asArray) && json_decode((string) json_encode($asArray)) == json_decode($text)) {
                    return $asArray;
                }
            }
        }

        return $text;
    }

    /** Untyped / string settings keep the old text form: bools '1'/'0', numbers as strings. */
    private static function toText(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_object($value) => json_decode((string) json_encode($value), true),
            default => $value,
        };
    }

    /** Pre-P1-07 string form of a typed value. */
    private static function toLegacy(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
