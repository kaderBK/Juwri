<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Phase 2 / item 16 (Octane worker mode): the identity of "this request" for every memo that must not outlive it.
 *
 * Process-wide static memos (settings, entitlements, store-keyed lookups...) are stamped with an id and cleared
 * when the stamp changes. Under php-fpm, Herd and the test runner the stamp used to be `spl_object_id(app())`: a new
 * application per request / per test. A long-lived Octane worker reuses the base application and hands each request a
 * CLONE, and PHP recycles object ids, so a fresh request could get the previous request's id and read its memo (a stale
 * setting, another store's value). {@see self::id()} keeps the application id and adds a counter that the Octane
 * listener ({@see \Juwri\Core\Octane\BeginRequest}) bumps at the start of every request, task and tick, so the stamp is
 * different for every unit of work whatever the object ids do. Outside Octane the counter stays 0 and the stamp is the
 * application id, exactly as before.
 */
final class RequestEpoch
{
    private static int $epoch = 0;

    /** A new unit of work (request, task, tick) starts: every memo stamped with the old id is stale. */
    public static function bump(): void
    {
        self::$epoch = (self::$epoch + 1) % 0x3FFFFFFF;
    }

    /** The stamp of the current unit of work: stable inside it, different from the previous one. */
    public static function id(): int
    {
        return spl_object_id(app()) | (self::$epoch << 32);
    }

    /** Is this process an Octane worker (long-lived, requests share one PHP process)? */
    public static function inWorker(): bool
    {
        return isset($_SERVER['LARAVEL_OCTANE']) || isset($_ENV['LARAVEL_OCTANE']);
    }
}
