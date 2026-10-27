<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\AccountUsage;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Sql;
use Tests\TestCase;

/**
 * P6-30: pins the behaviour of the constructs the internal PostgreSQL audit lists as "differs" or risky on
 * PostgreSQL. Runs on every driver; the expected value is chosen per driver, so a change in either direction
 * (a driver swap, a Laravel upgrade that changes whereLike, a collation change) fails here first.
 */
class PgSqlPortabilityAuditTest extends TestCase
{
    use RefreshDatabase;

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function test_where_like_helper_is_case_insensitive_on_every_driver_but_the_like_operator_is_not_on_pgsql(): void
    {
        User::factory()->create(['name' => 'Zed Quux']);

        // whereLike is what the admin lists use (30 files): ILIKE on PostgreSQL.
        $this->assertSame(1, User::query()->whereLike('name', '%zed quux%')->count());

        // The raw `like` operator (MediaReferences, StoreDataEngine): case-sensitive only on PostgreSQL.
        $expected = DB::getDriverName() === 'pgsql' ? 0 : 1;
        $this->assertSame($expected, User::query()->where('name', 'like', '%zed quux%')->count());
    }

    public function test_equality_on_text_columns_is_case_insensitive_only_on_mysql(): void
    {
        // P6-40: the model stores emails lowercased, so write the mixed-case value past it.
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['email' => 'MixedCase@example.test']);

        $this->assertSame(1, User::query()->where('email', 'MixedCase@example.test')->count());
        $this->assertSame($this->isMysql() ? 1 : 0, User::query()->where('email', 'mixedcase@example.test')->count());
        // The portable way, used by the menu importers: compare LOWER(col) with a lowercased binding.
        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['mixedcase@example.test'])->count());
    }

    public function test_ascending_order_puts_nulls_first_on_mysql_and_sqlite_but_last_on_pgsql(): void
    {
        User::factory()->create(['email_verified_at' => null]);
        User::factory()->create(['email_verified_at' => now()]);

        $first = User::query()->orderBy('email_verified_at')->orderBy('id')->first();
        $nullsFirst = DB::getDriverName() !== 'pgsql';
        $this->assertSame($nullsFirst, $first->email_verified_at === null);

        // Sql::orderByDescNullsLast is the portable form: a set value first, NULL last, on every driver.
        $desc = Sql::orderByDescNullsLast(User::query(), 'email_verified_at')->first();
        $this->assertNotNull($desc->email_verified_at);
    }

    public function test_account_usage_decrement_is_clamped_at_zero(): void
    {
        $user = User::factory()->create();
        AccountUsage::put($user->id, 'ai_calls', 5);

        AccountUsage::adjust($user->id, 'ai_calls', -3);
        $this->assertSame(2, AccountUsage::value($user->id, 'ai_calls'));

        AccountUsage::adjust($user->id, 'ai_calls', -10);
        $this->assertSame(0, AccountUsage::value($user->id, 'ai_calls'));
    }

    public function test_insert_or_ignore_twice_in_a_transaction_does_not_abort_it(): void
    {
        $row = ['day' => '2026-10-13', 'route_name' => 'audit', 'surface' => 'app', 'device' => 'ios', 'user_id' => 1,
            'views' => 1, 'created_at' => now(), 'updated_at' => now()];

        DB::transaction(function () use ($row) {
            DB::table('owner_screen_views')->insertOrIgnore($row);
            DB::table('owner_screen_views')->insertOrIgnore($row);
            // PostgreSQL would reject every later statement if the duplicate had raised instead of being ignored.
            $this->assertSame(1, DB::table('owner_screen_views')->where('route_name', 'audit')->count());
        });
    }

    public function test_grouped_aggregates_with_an_output_alias_and_count_alias_run(): void
    {
        $user = User::factory()->create();
        AccountUsage::put($user->id, 'a', 4);
        AccountUsage::put($user->id, 'b', 6);

        // Same shapes as PlatformMetrics / AbuseGuardController: alias `c`, SUM over a column named `value`.
        $rows = AccountUsage::query()->selectRaw('user_id, sum(value) as c')->groupBy('user_id')->get();
        $this->assertSame(10, (int) $rows->first()->getAttribute('c'));

        $byDay = DB::table('owner_screen_views')->selectRaw('DATE(day) as d, COUNT(1) as count')->groupBy('d')->orderByDesc('count')->get();
        $this->assertCount(0, $byDay);
    }
}
