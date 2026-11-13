<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Juwri\Core\Editions\EditionPackages;

/**
 * Old class names of code moved in P1-08 keep working (P1-08a).
 *
 * The map lives in legacy_class_aliases.php next to this file (generated), plus the alias file of every installed
 * edition package (P1-13 13i: named in its juwri-edition.php manifest, see EditionPackages; merged after the
 * Community map, so its classes keep their old names too). register() (called from bootstrap/app.php)
 * adds an spl autoloader that creates `class_alias(new, old)` only when the OLD name is
 * asked for, so nothing is loaded up front. That covers serialized queue payloads
 * (jobs, mail), cached objects, string controller references and class names stored
 * in the database (activity log subject_type, …).
 *
 * Eloquent models keep their old name as the morph type (morphMap(): old name =>
 * current class), so polymorphic *_type columns (notifiable_type, tokenable_type, …)
 * keep their stored values.
 */
final class LegacyClassAliases
{
    /** @var array{aliases: array<string, string>, models: list<string>}|null */
    private static ?array $data = null;

    /** @var array<string, string>|null lower(old name) => current name */
    private static ?array $lower = null;

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $target = self::lower()[strtolower(ltrim($class, '\\'))] ?? null;
            if ($target === null || strcasecmp($target, $class) === 0) {
                return;
            }
            if (class_exists($target) || interface_exists($target) || trait_exists($target) || enum_exists($target)) {
                class_alias($target, $class, false);
            }
        });
    }

    /** @return array<string, string> old FQCN => current FQCN */
    public static function aliases(): array
    {
        return self::data()['aliases'];
    }

    /** @return array<string, class-string> old model FQCN (= stored morph type) => current class */
    public static function morphMap(): array
    {
        $aliases = self::aliases();
        $map = [];
        foreach (self::data()['models'] as $old) {
            if (isset($aliases[$old])) {
                /** @var class-string $class */
                $class = $aliases[$old];
                $map[$old] = $class;
            }
        }

        return $map;
    }

    /** The name a moved class had before P1-08 (the first one, if it moved twice). */
    public static function oldNameOf(string $class): ?string
    {
        $class = ltrim($class, '\\');
        $found = null;
        foreach (self::aliases() as $old => $current) {
            if (strcasecmp($current, $class) === 0) {
                if (str_starts_with($old, 'App\\')) {
                    return $old; // the pre-P1-08 name wins over an intermediate one
                }
                $found ??= $old;
            }
        }

        return $found;
    }

    /** @return array{aliases: array<string, string>, models: list<string>} */
    private static function data(): array
    {
        if (self::$data === null) {
            $aliases = [];
            $models = [];
            foreach ([__DIR__.'/legacy_class_aliases.php', ...EditionPackages::aliasFiles()] as $file) {
                $data = is_file($file) ? require $file : [];
                $aliases += is_array($data['aliases'] ?? null) ? $data['aliases'] : [];
                array_push($models, ...array_values(is_array($data['models'] ?? null) ? $data['models'] : []));
            }
            self::$data = ['aliases' => $aliases, 'models' => array_values(array_unique($models))];
        }

        return self::$data;
    }

    /** @return array<string, string> */
    private static function lower(): array
    {
        if (self::$lower === null) {
            self::$lower = [];
            foreach (self::aliases() as $old => $current) {
                self::$lower[strtolower($old)] = $current;
            }
        }

        return self::$lower;
    }
}
