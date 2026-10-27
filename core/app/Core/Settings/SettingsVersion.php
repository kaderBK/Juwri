<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Settings;

/**
 * Process-wide, monotonic counter of store-settings changes (writes, flushes, a new unit of work's memo scope).
 *
 * Derived memos (StoreOption) stamp the number they saw and drop their values when it moves. It must never restart:
 * a restart at 0 in a new app let a later write land on a number a reader had already stamped, so an early
 * store_currency() read (platform fallback) outlived the store's currency_code write. It is a counter, not a memo of
 * data, so it lives here — outside StoreSettings' app-scoped memos — and is allowlisted in StaticMemoGuardLint.
 */
final class SettingsVersion
{
    private static int $n = 0;

    public static function bump(): void
    {
        self::$n++;
    }

    public static function current(): int
    {
        return self::$n;
    }
}
