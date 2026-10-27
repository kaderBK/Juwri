<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T2 — the sqlite test connection must honor DB_PREFIX so the prod qrtest_
 * prefix (and the raw-SQL hardcoded-table bug class it exposes) is testable in
 * CI. With DB_PREFIX unset the prefix is '' — byte-identical to before.
 */
class SqlitePrefixTest extends TestCase
{
    public function test_sqlite_connection_reads_db_prefix_env(): void
    {
        $expected = (string) env('DB_PREFIX', '');

        $this->assertSame(
            $expected,
            config('database.connections.sqlite.prefix'),
            'sqlite connection prefix must resolve from DB_PREFIX'
        );

        // Whatever the prefix, the active connection's tablePrefix agrees, so the
        // query builder prepends it transparently (models/getTable stay correct).
        $this->assertSame(
            config('database.connections.sqlite.prefix'),
            DB::connection('sqlite')->getTablePrefix()
        );
    }
}
