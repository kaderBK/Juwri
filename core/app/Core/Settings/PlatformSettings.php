<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Settings;

use App\Support\PlatformSecrets;
use Juwri\Core\Support\SecretMask;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\PlatformSetting;
use Throwable;

/**
 * P1-07: typed platform settings — the one reader/writer of the `platform_settings` table
 * (replaces App\Models\Option and the `options` table).
 *
 * - Values are stored as JSON with the type PlatformSettingKeys declares (bool / int / array /
 *   string). Writes are lenient and lossless: only '1'/'0' become bools and only integer text
 *   becomes an int; anything else (e.g. "12.5" for an int, "off" for a flag) is kept as given.
 * - Credentials (PlatformSecrets::KEYS) are encrypted at rest; a masked echo from a settings form
 *   keeps the stored secret. Typed reads return plaintext; only runtime code may call them —
 *   views get masked values (masked()).
 * - legacy()/legacyMap() give the pre-P1-07 string form ('1'/'0', numbers as strings, JSON text)
 *   so `config('settings.*')` — filled by SettingsServiceProvider — keeps its exact shape.
 * - The whole table is cached forever (still encrypted) and memoised per application instance;
 *   every write drops both.
 */
final class PlatformSettings
{
    public const CACHE_KEY = 'settings.platform';

    /** @var array<string, string|null>|null name => stored JSON text (secrets encrypted) */
    private static ?array $memo = null;

    private static ?int $memoApp = null;

    /** Typed value (secrets decrypted), or $default when unset / null. */
    public static function get(string $name, mixed $default = null): mixed
    {
        $stored = self::storedMap();
        if (! array_key_exists($name, $stored) || $stored[$name] === null) {
            return $default;
        }
        $value = self::decode($stored[$name]);
        if ($value === null) {
            return $default;
        }

        return PlatformSecrets::isSecret($name) ? PlatformSecrets::decrypt($value) : $value;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(string $name, int $default = 0): int
    {
        $value = self::get($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(string $name, string $default = ''): string
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }

        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /**
     * @param  array<array-key, mixed>  $default
     * @return array<array-key, mixed>
     */
    public static function array(string $name, array $default = []): array
    {
        $value = self::get($name);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && json_validate($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $default;
        }

        return $default;
    }

    /** True when a row exists for $name (even with an empty value). */
    public static function has(string $name): bool
    {
        return array_key_exists($name, self::storedMap());
    }

    /**
     * The pre-P1-07 string form of one setting (secrets decrypted), null when unset:
     * bool '1'/'0', numbers as strings, arrays as JSON text.
     */
    public static function legacy(string $name): ?string
    {
        $stored = self::storedMap()[$name] ?? null;
        if ($stored === null) {
            return null;
        }
        $legacy = self::toLegacy($stored);

        return PlatformSecrets::isSecret($name) && $legacy !== null ? PlatformSecrets::decrypt($legacy) : $legacy;
    }

    /** Like legacy(), but a secret stays encrypted — the value as it sits at rest. */
    public static function stored(string $name): ?string
    {
        $stored = self::storedMap()[$name] ?? null;

        return $stored === null ? null : self::toLegacy($stored);
    }

    /**
     * Every setting in the legacy string form.
     *
     * @param  bool  $decrypt  false keeps credentials encrypted (pair it with PlatformSecrets::maskMap for views)
     * @return array<string, string|null>
     */
    public static function legacyMap(bool $decrypt = true): array
    {
        $map = array_map(fn ($stored) => $stored === null ? null : self::toLegacy($stored), self::storedMap());

        return $decrypt ? PlatformSecrets::decryptMap($map) : $map;
    }

    /**
     * Every setting for display: credentials replaced by their mask, JSON values decoded
     * (objects), the shape views used to get from the options table.
     *
     * @return array<string, mixed>
     */
    public static function masked(): array
    {
        try {
            $map = PlatformSecrets::maskMap(self::legacyMap(false));
        } catch (Throwable) {
            return [];
        }

        return array_map(fn ($v) => is_string($v) && json_validate($v) ? json_decode($v) : $v, $map);
    }

    /**
     * Names that start with $prefix (dynamic per-id keys).
     *
     * @return list<string>
     */
    public static function namesWithPrefix(string $prefix): array
    {
        return array_values(array_filter(array_keys(self::storedMap()), fn ($n) => str_starts_with((string) $n, $prefix)));
    }

    /**
     * Save one setting (typed per PlatformSettingKeys; credentials encrypted, a masked echo keeps
     * the stored secret). Returns true on success.
     */
    public static function set(string $name, mixed $value): bool
    {
        if (PlatformSecrets::isSecret($name)) {
            if (SecretMask::isMasked($value)) {
                return true;
            }
            if (is_string($value) && $value !== '' && ! PlatformSecrets::isEncrypted($value)) {
                $value = PlatformSecrets::encrypt($value);
            }
        }

        $row = PlatformSetting::query()->where('name', $name)->first() ?? new PlatformSetting(['name' => $name]);
        $row->value = self::encode($name, $value);
        $saved = $row->save();
        self::flush();

        return $saved;
    }

    /** @param  array<string, mixed>  $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $name => $value) {
            self::set((string) $name, $value);
        }
    }

    public static function forget(string $name): void
    {
        PlatformSetting::query()->where('name', $name)->delete();
        self::flush();
    }

    /** Drop the cross-request cache and the per-request memo. */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$memo = null;
    }

    /**
     * name => stored JSON text, cached forever (secrets stay encrypted in the cache).
     *
     * @return array<string, string|null>
     */
    public static function storedMap(): array
    {
        $app = \Juwri\Core\Support\RequestEpoch::id();
        if (self::$memoApp !== $app) {
            self::$memoApp = $app;
            self::$memo = null;
        }

        return self::$memo ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn () => PlatformSetting::query()->pluck('value', 'name')->all()
        );
    }

    /** JSON text for a value, typed by the key registry (lenient: an ill-fitting value is kept). */
    public static function encode(string $name, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $typed = match (PlatformSettingKeys::type($name)) {
            'bool' => self::toBool($value),
            'int' => self::toInt($value),
            'array' => self::toArray($value),
            default => self::toText($value),
        };

        // A JSON document given as text for an array setting is stored as that document.
        if (is_string($typed) && PlatformSettingKeys::type($name) === 'array' && self::isJsonDocument($typed)) {
            return $typed;
        }

        return json_encode($typed, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Typed PHP value of a stored JSON text (arrays associative). */
    public static function decode(?string $stored): mixed
    {
        if ($stored === null) {
            return null;
        }

        return json_decode($stored, true);
    }

    /** Pre-P1-07 string form of a stored JSON text. */
    private static function toLegacy(string $stored): ?string
    {
        $value = json_decode($stored);

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $value,
            default => $stored, // array / object: the JSON text itself ({} stays {})
        };
    }

    private static function toBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        // Only the forms the options table held for a flag ('1'/'0'); words such as 'on' or
        // 'true' are kept as text so their legacy form (and config value) does not change.
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
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', trim($value))) {
            return (int) trim($value);
        }

        return self::toText($value);
    }

    private static function toArray(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode((string) json_encode($value), true);
        }

        return self::toText($value);
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

    private static function isJsonDocument(string $text): bool
    {
        $trimmed = ltrim($text);

        return ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) && json_validate($text);
    }
}
