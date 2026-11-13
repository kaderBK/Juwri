<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * P1-13: named places in Community code that ee modules fill (e.g. the order lifecycle: Marketplace shopper push and
 * delivery WhatsApp, Channels aggregator status push). Community code runs a slot where it used to call the ee
 * module directly, so the order of side effects is unchanged; with ee absent a slot is empty. Hooks are
 * best-effort: an exception is reported nowhere and never reaches the caller (same as the try/catch they replace).
 */
final class Hooks
{
    /** @var array<string, array<string, callable>> slot => [name => hook] */
    private static array $hooks = [];

    /** Register (idempotently, by name) a hook in a slot. Call from a module provider. */
    public static function on(string $slot, string $name, callable $hook): void
    {
        self::$hooks[$slot][$name] = $hook;
    }

    /** Whether any hook is registered in a slot (a module that fills it is installed and licensed). */
    public static function has(string $slot): bool
    {
        return ! empty(self::$hooks[$slot]);
    }

    /** Run every hook of a slot, best-effort. */
    public static function run(string $slot, mixed ...$args): void
    {
        foreach (self::$hooks[$slot] ?? [] as $hook) {
            try {
                $hook(...$args);
            } catch (\Throwable $e) {
                // best-effort only
            }
        }
    }

    /**
     * Run every hook of a slot, letting an exception through (for work that was inline and could fail its caller,
     * e.g. a console command's step that moved into an ee module).
     */
    public static function call(string $slot, mixed ...$args): void
    {
        foreach (self::$hooks[$slot] ?? [] as $hook) {
            $hook(...$args);
        }
    }

    /**
     * The answer of a slot's first hook, letting an exception through (a controller action whose work moved into an
     * ee module: its response, validation errors and failures stay as they were). Null when the slot is empty.
     */
    public static function answer(string $slot, mixed ...$args): mixed
    {
        foreach (self::$hooks[$slot] ?? [] as $hook) {
            return $hook(...$args);
        }

        return null;
    }

    /** The first non-null answer of a slot's hooks, or null. */
    public static function first(string $slot, mixed ...$args): mixed
    {
        foreach (self::$hooks[$slot] ?? [] as $hook) {
            try {
                $answer = $hook(...$args);
            } catch (\Throwable $e) {
                continue;
            }
            if ($answer !== null) {
                return $answer;
            }
        }

        return null;
    }
}
