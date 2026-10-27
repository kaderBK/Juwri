<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

/**
 * Customizer v2 — canonical key resolution.
 *
 * One concept, one id. Legacy ids listed in config/theme-aliases.php resolve to
 * their canonical id on read (so old themes and old exports keep working) and
 * are folded away on write (so nothing new is ever stored under an alias).
 */
class ThemeKeys
{
    /** @var array<string,array<string,string>>|null */
    private static ?array $map = null;

    /** @return array<string,string> alias => canonical, for one template */
    public static function aliasesFor(string $template): array
    {
        if (self::$map === null) {
            $config = config('theme-aliases');
            self::$map = is_array($config) ? $config : [];
        }

        $key = \App\Services\ThemeCustomizerService::settingsKey($template);
        $global = is_array(self::$map['*'] ?? null) ? self::$map['*'] : [];
        $theme = is_array(self::$map[$key] ?? null) ? self::$map[$key] : [];

        return array_merge($global, $theme);
    }

    /** Test seam: re-read the alias config. */
    public static function flush(): void
    {
        self::$map = null;
    }

    /** Resolve one key to its canonical id (identity when it is not an alias). */
    public static function canonical(string $template, string $key): string
    {
        return self::aliasesFor($template)[$key] ?? $key;
    }

    /**
     * Fold a stored/submitted value set onto canonical ids.
     *
     * When both an alias and its canonical id carry a value the canonical one
     * wins — it is the id the editor writes and the one a theme reads first.
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public static function fold(string $template, array $values): array
    {
        $aliases = self::aliasesFor($template);
        if ($aliases === []) {
            return $values;
        }

        $out = [];
        foreach ($values as $key => $val) {
            $canonical = $aliases[(string) $key] ?? (string) $key;
            if ($canonical !== $key && array_key_exists($canonical, $values)) {
                continue; // canonical wins; drop the alias copy
            }
            $out[$canonical] = $val;
        }

        return $out;
    }
}
