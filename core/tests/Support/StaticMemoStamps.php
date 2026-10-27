<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use ReflectionClass;

/**
 * Clears every static memo's app-scope stamp before each test.
 *
 * The memos rebind on `spl_object_id(app())`, but PHP recycles object ids: once a test's application is
 * garbage-collected, a later test's application can get the SAME id, the stamp matches, and the memo (store id =>
 * account, precision, settings ...) from the earlier test leaks into it. Ids repeat across rolled-back databases,
 * so the leak shows as wrong data only in some full-suite orders (DashNotifPrefsCountsTest: 0 instead of 2). A null
 * stamp never equals an id, so the guard drops the memo on the next read, exactly as for a fresh application.
 */
final class StaticMemoStamps
{
    private const STAMPS = ['memoApp', 'cacheApp', 'app'];

    private static int $seen = 0;

    /** @var list<\ReflectionProperty> */
    private static array $targets = [];

    public static function reset(): void
    {
        $classes = get_declared_classes();
        if (count($classes) !== self::$seen) {
            foreach (array_slice($classes, self::$seen) as $class) {
                if (! str_starts_with($class, 'App\\') && ! str_starts_with($class, 'Juwri\\')) {
                    continue;
                }
                $ref = new ReflectionClass($class);
                foreach (self::STAMPS as $name) {
                    if (! $ref->hasProperty($name)) {
                        continue;
                    }
                    $prop = $ref->getProperty($name);
                    if ($prop->isStatic() && $prop->getType()?->allowsNull() && $prop->getType()->getName() === 'int') {
                        self::$targets[] = $prop;
                    }
                }
            }
            self::$seen = count($classes);
        }

        foreach (self::$targets as $prop) {
            $prop->setValue(null, null);
        }
    }
}
