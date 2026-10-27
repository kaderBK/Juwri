<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use App\Support\LegalMarkerScanner;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Support\RequestEpoch;
use Throwable;

/**
 * J51b: the owner-editable legal-page facts ({{FILL}} / {{VERIFY}} markers) stored in the platform
 * settings store, with .env (LEGAL_*) as the fallback. One row per key:
 *
 * - `legal_fill_<key>`    string, overrides env when non-empty (empty/absent = env value)
 * - `legal_verify_<key>`  '1'/'0', a row overrides env
 * - `legal_unused_<x>`    '1'/'0', x in {@see self::SUBPROCESSORS}; '1' = "Not used", the matching
 *                          Sub-processors table row is removed.
 *
 * `applyToConfig()` projects those rows into config('legal.*') on every request; `save()` writes them
 * and returns the audit diff. `groups()` is the field registry the admin form renders, `status()` the
 * live "X of N blanks filled" figure for the form header.
 */
final class LegalSettings
{
    /** Removable sub-processor keys ("hosting" is never removable). */
    public const SUBPROCESSORS = ['backup', 'cdn', 'email', 'messaging', 'ai', 'maps', 'monitoring', 'support', 'analytics', 'push', 'logging'];

    /** @var array<string, array{env: mixed, set: mixed}> config keys this class overrode: the value before, and what it set */
    private static array $overridden = [];

    /** The app/request the override memo belongs to (RequestEpoch::id()). */
    private static ?int $memoApp = null;

    /** A new app/request starts with a fresh config, so what this class overrode before is forgotten. */
    private static function scopeMemo(): void
    {
        $app = RequestEpoch::id();
        if (self::$memoApp !== $app) {
            self::$memoApp = $app;
            self::$overridden = [];
        }
    }

    /** Fill values are capped at this many characters. */
    private const MAX_LENGTH = 500;

    /**
     * The form field registry, one group per card. `title` is the English source text (the view calls
     * ___() at render time), `fields` list every fill/verify key exactly once.
     *
     * @return list<array{id: string, title: string, fields: list<array{key: string, kind: string, input?: string, unusable?: string}>}>
     */
    public static function groups(): array
    {
        $fill = fn (string $key, string $input = 'text', ?string $unusable = null): array => [
            'key' => $key,
            'kind' => 'fill',
            'input' => $input,
        ] + ($unusable === null ? [] : ['unusable' => $unusable]);

        $groups = [
            [
                'id' => 'hosting',
                'title' => 'Hosting & backups',
                'fields' => [
                    $fill('hosting_provider'),
                    $fill('hosting_region'),
                    $fill('hosting_certifications'),
                    $fill('vendor_hosting'),
                    $fill('vendor_backup', 'text', 'backup'),
                    $fill('backup_region', 'text', 'backup'),
                ],
            ],
            [
                'id' => 'subprocessors',
                'title' => 'Sub-processors',
                'fields' => [],
            ],
            [
                'id' => 'contacts',
                'title' => 'Contacts & support',
                'fields' => [
                    $fill('whatsapp_number'),
                    $fill('support_phone'),
                    $fill('holiday_calendar_url', 'url'),
                    $fill('higher_plans'),
                ],
            ],
            [
                'id' => 'registrations',
                'title' => 'Registrations',
                'fields' => [
                    $fill('ico_number'),
                    $fill('egypt_representative'),
                    $fill('analytics_tool'),
                ],
            ],
            [
                'id' => 'security',
                'title' => 'Security',
                'fields' => [
                    $fill('pgp_fingerprint'),
                    $fill('github_url', 'url'),
                ],
            ],
            [
                'id' => 'promises',
                'title' => 'Product promises (confirm each is true)',
                'fields' => [],
            ],
        ];

        foreach (self::SUBPROCESSORS as $x) {
            if ($x === 'backup') {
                continue;
            }
            $groups[1]['fields'][] = $fill('vendor_'.$x, 'text', $x);
            $groups[1]['fields'][] = $fill('location_'.$x, 'text', $x);
        }

        foreach (array_keys((array) config('legal.verify', [])) as $key) {
            $groups[5]['fields'][] = ['key' => $key, 'kind' => 'verify'];
        }

        return $groups;
    }

    /**
     * Project the platform settings rows into config('legal.*'). A non-empty `legal_fill_<key>` wins
     * over env; a present `legal_verify_<key>` wins over env; `legal_unused_<x>` becomes a bool
     * (default false). Safe when the settings table is unreachable: config is left alone.
     */
    public static function applyToConfig(): void
    {
        self::scopeMemo();

        try {
            $wanted = [];
            foreach (array_keys((array) config('legal.fill', [])) as $key) {
                $value = PlatformSettings::string('legal_fill_'.$key);
                $wanted['legal.fill.'.$key] = $value !== '' ? $value : null;
            }
            foreach (array_keys((array) config('legal.verify', [])) as $key) {
                $name = 'legal_verify_'.$key;
                $wanted['legal.verify.'.$key] = PlatformSettings::has($name) ? PlatformSettings::bool($name) : null;
            }

            foreach ($wanted as $configKey => $value) {
                $mine = self::$overridden[$configKey] ?? null;
                if ($value !== null) {
                    // First override remembers the file/.env value so clearing the DB row can restore it.
                    // A config that no longer holds what this class set was reset from outside (new request): its value is the env value.
                    $env = ($mine !== null && config($configKey) === $mine['set']) ? $mine['env'] : config($configKey);
                    self::$overridden[$configKey] = ['env' => $env, 'set' => $value];
                    config([$configKey => $value]);
                } elseif ($mine !== null) {
                    // Cleared in the DB: back to the file/.env value (unless something else changed the config since).
                    if (config($configKey) === $mine['set']) {
                        config([$configKey => $mine['env']]);
                    }
                    unset(self::$overridden[$configKey]);
                }
            }

            foreach (self::SUBPROCESSORS as $x) {
                config(['legal.unused.'.$x => PlatformSettings::bool('legal_unused_'.$x)]);
            }
        } catch (Throwable) {
            // Settings table unreachable (fresh deploy, maintenance): keep the env-backed config.
        }
    }

    /** Whether sub-processor $x is marked "Not used" (its table row is removed). */
    public static function isUnused(string $x): bool
    {
        return (bool) config('legal.unused.'.$x, false);
    }

    /**
     * The effective values as the form shows them (DB where set, else env).
     *
     * @return array{fill: array<string, string>, verify: array<string, bool>, unused: array<string, bool>}
     */
    public static function effective(): array
    {
        $fill = [];
        foreach (array_keys((array) config('legal.fill', [])) as $key) {
            $fill[$key] = (string) config('legal.fill.'.$key, '');
        }
        $verify = [];
        foreach (array_keys((array) config('legal.verify', [])) as $key) {
            $verify[$key] = (bool) config('legal.verify.'.$key, false);
        }
        $unused = [];
        foreach (self::SUBPROCESSORS as $x) {
            $unused[$x] = self::isUnused($x);
        }

        return ['fill' => $fill, 'verify' => $verify, 'unused' => $unused];
    }

    /**
     * Persist the form values and return the audit diff (only rows that actually changed; the settings
     * store is the source for "from"). Fill: trim, empty forgets the row (env fallback), else set.
     * Verify and unused: set '1'/'0' for every known key. Unknown keys are ignored.
     *
     * @param  array<array-key, mixed>  $fill
     * @param  array<array-key, mixed>  $verify
     * @param  array<array-key, mixed>  $unused
     * @return list<array{name: string, from: string, to: string}>
     */
    public static function save(array $fill, array $verify, array $unused): array
    {
        $changes = [];

        foreach (array_keys((array) config('legal.fill', [])) as $key) {
            if (! array_key_exists($key, $fill)) {
                continue;
            }
            $name = 'legal_fill_'.$key;
            $trimmed = trim((string) ($fill[$key] ?? ''));
            $old = PlatformSettings::stored($name);
            if ($trimmed === '') {
                if ($old !== null) {
                    PlatformSettings::forget($name);
                    $changes[] = ['name' => $name, 'from' => $old, 'to' => ''];
                }

                continue;
            }
            $value = mb_substr(self::stripControlChars($trimmed), 0, self::MAX_LENGTH);
            if ($old !== $value) {
                PlatformSettings::set($name, $value);
                $changes[] = ['name' => $name, 'from' => $old ?? '', 'to' => $value];
            }
        }

        foreach (array_keys((array) config('legal.verify', [])) as $key) {
            $name = 'legal_verify_'.$key;
            $value = self::truthy($verify[$key] ?? null) ? '1' : '0';
            $old = PlatformSettings::stored($name);
            if ($old !== $value) {
                PlatformSettings::set($name, $value);
                $changes[] = ['name' => $name, 'from' => $old ?? '', 'to' => $value];
            }
        }

        foreach (self::SUBPROCESSORS as $x) {
            $name = 'legal_unused_'.$x;
            $value = self::truthy($unused[$x] ?? null) ? '1' : '0';
            $old = PlatformSettings::stored($name);
            if ($old !== $value) {
                PlatformSettings::set($name, $value);
                $changes[] = ['name' => $name, 'from' => $old ?? '', 'to' => $value];
            }
        }

        PlatformSettings::flush();
        self::applyToConfig();

        return $changes;
    }

    /**
     * The live "X of N blanks filled" figure for the form header. Scans the English legal texts only
     * (EN and AR are identical), counts every marker occurrence, and treats one as filled when its own
     * value is set (FILL non-empty / VERIFY true) or when its line is a Sub-processors table row removed
     * because its vendor is marked "Not used".
     *
     * @return array{total: int, filled: int, ready: bool, pages: array<string, array{total: int, remaining: int}>}
     */
    public static function status(): array
    {
        try {
            $entries = (new LegalMarkerScanner)->scan(LegalPage::root());
        } catch (Throwable) {
            return ['total' => 0, 'filled' => 0, 'ready' => false, 'pages' => []];
        }

        $fill = (array) config('legal.fill', []);
        $verify = (array) config('legal.verify', []);
        $dropped = self::droppedLines();

        $total = 0;
        $filled = 0;
        $pages = [];
        foreach ($entries as $entry) {
            if ($entry['locale'] !== 'en') {
                continue;
            }
            $total++;

            $isFilled = $entry['type'] === 'FILL'
                ? trim((string) ($fill[$entry['key']] ?? '')) !== ''
                : (bool) ($verify[$entry['key']] ?? false);

            if (! $isFilled && in_array($entry['line'], $dropped[$entry['document']] ?? [], true)) {
                $isFilled = true;
            }

            $pages[$entry['document']] ??= ['total' => 0, 'remaining' => 0];
            $pages[$entry['document']]['total']++;
            if ($isFilled) {
                $filled++;
            } else {
                $pages[$entry['document']]['remaining']++;
            }
        }
        ksort($pages);

        return ['total' => $total, 'filled' => $filled, 'ready' => $filled === $total, 'pages' => $pages];
    }

    /**
     * A Markdown table line that must be dropped: it starts with `|` and holds a {{FILL:vendor_<x>}}
     * marker for a sub-processor marked "Not used" (the whole row is removed).
     */
    public static function isUnusedLine(string $line): bool
    {
        if (! str_starts_with(ltrim($line), '|')) {
            return false;
        }
        if (preg_match_all('/\{\{FILL:vendor_([a-z0-9_]+)\}\}/u', $line, $m) === 0) {
            return false;
        }
        foreach ($m[1] as $x) {
            if (self::isUnused($x)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Line numbers (1-based) per English document of the Sub-processors rows removed by "Not used".
     *
     * @return array<string, list<int>>
     */
    private static function droppedLines(): array
    {
        $dropped = [];
        $root = LegalPage::root().'/en';
        if (! is_dir($root)) {
            return $dropped;
        }
        foreach (glob($root.'/*.md') ?: [] as $file) {
            $document = (string) pathinfo($file, PATHINFO_FILENAME);
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $i => $line) {
                if (self::isUnusedLine($line)) {
                    $dropped[$document][] = $i + 1;
                }
            }
        }

        return $dropped;
    }

    /** A submitted checkbox/switch value counts as on ('1' or true). */
    private static function truthy(mixed $value): bool
    {
        return $value === '1' || $value === true || $value === 1;
    }

    /** Strip ASCII control characters (C0 + DEL) from a saved value. */
    private static function stripControlChars(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }
}
