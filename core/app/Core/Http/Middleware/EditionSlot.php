<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;

/**
 * P1-13: a named place in a middleware group (app/Http/Kernel) that ee module providers fill — Community never
 * names an ee middleware class. `EditionSlot::class.':web-guards'` runs every middleware registered for that slot
 * (in `order`, then registration order) exactly where the Kernel lists the slot; an empty slot passes through.
 *
 * Group membership cannot be registered from a provider: the HTTP kernel re-applies its own groups when it is
 * constructed (after the providers in tests), so the slot is the stable anchor. The kernel calls terminate() on the
 * slot only, without its parameter, so slot middleware must not rely on terminate().
 */
final class EditionSlot
{
    /** @var array<string, array<class-string, int>> slot => [middleware class => order] */
    private static array $slots = [];

    /** Register (or re-register, idempotently) a middleware class in a slot. Call from a provider's register(). */
    public static function add(string $slot, string $middleware, int $order = 0): void
    {
        self::$slots[$slot][$middleware] = $order;
    }

    /** @return list<class-string> the slot's middleware, in run order */
    public static function middleware(string $slot): array
    {
        $rows = [];
        foreach (array_keys(self::$slots[$slot] ?? []) as $i => $class) {
            $rows[] = [self::$slots[$slot][$class], $i, $class];
        }
        sort($rows); // by order, ties keep registration order

        return array_column($rows, 2);
    }

    public function handle(Request $request, Closure $next, string $slot): mixed
    {
        $middleware = self::middleware($slot);
        if ($middleware === []) {
            return $next($request);
        }

        return (new Pipeline(app()))->send($request)->through($middleware)->then($next);
    }
}
