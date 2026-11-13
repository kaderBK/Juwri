<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Closure;
use Illuminate\Console\Scheduling\Schedule;

/**
 * P1-13: named places in the console schedule (app/Console/Kernel) that ee modules fill. The Kernel applies a slot
 * where it used to list the ee module's commands, so the order of `schedule:run` stays as it was; with ee absent a
 * slot is empty (no "command not found" every minute). Entries in one slot run by `order`, then registration order.
 *
 * A definition receives the Schedule and the Kernel's failure-alert factory (`$onFail('name')`).
 */
final class ScheduleSlots
{
    /** @var array<string, array<string, array{order: int, define: Closure(Schedule, Closure(string): Closure): void}>> */
    private static array $slots = [];

    /** Register (idempotently, by name) a schedule definition in a slot. Call from a module provider. */
    public static function add(string $slot, string $name, Closure $define, int $order = 0): void
    {
        self::$slots[$slot][$name] = ['order' => $order, 'define' => $define];
    }

    /** @param  Closure(string): Closure  $onFail */
    public static function apply(string $slot, Schedule $schedule, Closure $onFail): void
    {
        $entries = self::$slots[$slot] ?? [];
        uasort($entries, fn (array $a, array $b) => $a['order'] <=> $b['order']);
        foreach ($entries as $entry) {
            ($entry['define'])($schedule, $onFail);
        }
    }

    /** @return list<string> the names registered in a slot, in run order (tests) */
    public static function names(string $slot): array
    {
        $entries = self::$slots[$slot] ?? [];
        uasort($entries, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_keys($entries);
    }
}
