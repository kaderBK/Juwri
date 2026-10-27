<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

/**
 * P6-135: MySQL and MariaDB spell "drop a CHECK constraint" differently. MySQL 8 knows `DROP CHECK name`; MariaDB (the usual
 * cPanel database) only knows `DROP CONSTRAINT name`, and a migration with the MySQL spelling stops a fresh install half-way.
 * `DROP CONSTRAINT` also exists on MySQL 8.0.19+, but older 8.0.x releases still need `DROP CHECK`, so the choice follows the server.
 */
final class MySqlDialect
{
    public static function dropCheckSql(string $table, string $name): string
    {
        $conn = DB::connection();
        $maria = $conn instanceof MySqlConnection && $conn->isMaria();

        return "ALTER TABLE {$table} ".($maria ? 'DROP CONSTRAINT ' : 'DROP CHECK ').$name;
    }
}
