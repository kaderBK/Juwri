<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\Support\StaticMemoGuardLint;
use Tests\TestCase;

/**
 * WI-R3 — the static-memo app-scope guard lint (the memo-leak claim was REFUTED;
 * this hardens the idiom so a NEW unstamped store-keyed memo fails CI). Reads
 * files, no database.
 */
class StaticMemoGuardLintTest extends TestCase
{
    public function test_a_class_with_an_unstamped_store_memo_is_reported(): void
    {
        $src = <<<'PHP'
        <?php
        namespace App\Services;
        class Widgets
        {
            private static array $priceMemo = [];
            public static function priceFor(int $id): float
            {
                return self::$priceMemo[$id] ??= 1.0;
            }
        }
        PHP;

        $findings = StaticMemoGuardLint::inspectSource('app/Services/Widgets.php', $src);

        $this->assertCount(1, $findings);
        $this->assertSame('missing-stamp', $findings[0]['kind']);
        $this->assertSame('priceMemo', $findings[0]['name']);
    }

    public function test_a_sibling_memo_the_guard_forgets_to_clear_is_reported(): void
    {
        $src = <<<'PHP'
        <?php
        namespace App\Services;
        class Widgets
        {
            private static array $a = [];
            private static array $b = [];
            private static ?int $memoApp = null;
            private static function scopeMemo(): void
            {
                $id = spl_object_id(app());
                if (self::$memoApp !== $id) {
                    self::$memoApp = $id;
                    self::$a = [];
                }
            }
        }
        PHP;

        $findings = StaticMemoGuardLint::inspectSource('app/Services/Widgets.php', $src);

        $this->assertCount(1, $findings);
        $this->assertSame('unclear-sibling', $findings[0]['kind']);
        $this->assertSame('b', $findings[0]['name']);
    }

    public function test_a_guard_that_delegates_to_flush_is_accepted(): void
    {
        $src = <<<'PHP'
        <?php
        namespace App\Services;
        class Widgets
        {
            private static array $memo = [];
            private static array $bundleCache = [];
            private static ?int $memoApp = null;
            private static function scopeMemo(): void
            {
                $id = spl_object_id(app());
                if (self::$memoApp !== $id) {
                    self::$memoApp = $id;
                    self::forget();
                }
            }
            public static function forget(): void
            {
                self::$memo = [];
                self::$bundleCache = [];
            }
        }
        PHP;

        $findings = StaticMemoGuardLint::inspectSource('app/Services/Widgets.php', $src);

        $this->assertSame([], $findings);
    }

    public function test_a_function_level_static_is_not_reported(): void
    {
        $src = <<<'PHP'
        <?php
        namespace App\Support;
        class Helpers
        {
            public static function f(): array
            {
                static $cache = [];
                return $cache;
            }
        }
        PHP;

        $this->assertSame([], StaticMemoGuardLint::inspectSource('app/Support/Helpers.php', $src));
    }

    public function test_the_whole_app_directory_is_clean(): void
    {
        $offenders = StaticMemoGuardLint::scanApp();

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Store-keyed static memo(s) without the app-scope guard. A static keyed by a database id'],
            ['says nothing about WHICH database the id came from; under the test runner RefreshDatabase'],
            ['reuses low ids and the memo answers for an unrelated store in the next class (POS totals'],
            ['came out halved, 24 -> 12, on 2026-07-31). Add the per-class guard — see'],
            ['Juwri\\Modules\\Tables\\Services\\TableMergeService::scopeMemo() — or, if this static is a driver'],
            ['registry / disk-config / test double, add it to StaticMemoGuardLint::ALLOWLIST with a reason.'],
            array_map(fn ($f) => ($f['reason'] ?? json_encode($f)), $offenders)
        )));
    }
}
