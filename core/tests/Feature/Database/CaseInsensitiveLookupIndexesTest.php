<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** P6-40b: expression indexes behind the P6-40 `LOWER(col) = ?` lookups, and the collision-safe lowercase backfill. */
class CaseInsensitiveLookupIndexesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'app/Core/Database/Migrations/2026_10_13_100000_add_case_insensitive_lookup_indexes.php';

    private const UNIQUE_MIGRATION = 'app/Core/Database/Migrations/2026_10_13_200000_make_case_insensitive_identity_indexes_unique.php';

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public function test_expression_indexes_exist_on_pgsql_and_sqlite_only(): void
    {
        // P6-98 replaced the users / customers email indexes with identical UNIQUE ones
        foreach (['users' => 'users_lower_email_unique', 'customers' => 'customers_store_id_lower_email_unique',
            'staff_members' => 'staff_members_store_id_lower_email_index', 'coupons' => 'coupons_lower_code_index'] as $table => $index) {
            $this->assertSame(in_array($this->driver(), ['pgsql', 'sqlite'], true), Schema::hasIndex($table, $index), $index);
        }
    }

    public function test_postgres_lookup_uses_the_lower_email_index(): void
    {
        if ($this->driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL planner check');
        }
        User::factory()->create(['email' => 'probe@example.com']);
        DB::statement('SET LOCAL enable_seqscan = off'); // tiny table: force the planner to show whether the index can serve it
        $plan = implode("\n", array_map(fn ($r) => (array) $r === [] ? '' : array_values((array) $r)[0],
            DB::select('EXPLAIN SELECT * FROM users WHERE LOWER(email) = ?', ['probe@example.com'])));
        $this->assertStringContainsString('users_lower_email_unique', $plan); // P6-98 unique replaces the P6-40b index
    }

    public function test_backfill_lowercases_unique_values_and_leaves_collisions(): void
    {
        if (! in_array($this->driver(), ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped('MySQL compares case-insensitively; no backfill there');
        }
        (require base_path(self::UNIQUE_MIGRATION))->down(); // P6-98 uniques would reject the case twins below
        $solo = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        DB::table('users')->where('id', $solo->id)->update(['email' => 'Solo@Example.COM']);
        DB::table('users')->where('id', $a->id)->update(['email' => 'Twin@Example.com']);
        DB::table('users')->where('id', $b->id)->update(['email' => 'twin@example.com']);

        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $migration->up();

        $this->assertSame('solo@example.com', DB::table('users')->where('id', $solo->id)->value('email'));
        $this->assertSame('Twin@Example.com', DB::table('users')->where('id', $a->id)->value('email'));
        $this->assertTrue(Schema::hasIndex('users', 'users_lower_email_index'));
    }
}
