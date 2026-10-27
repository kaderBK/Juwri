<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Database\Connection;

/**
 * Plan §1.1 rule 1: after a real import, each written table's id sequence / auto-increment is moved so the next new
 * row gets id max(id) + gap (default 1000), leaving a visible gap between imported and new ids. SQLite
 * (sqlite_sequence), MySQL/MariaDB (AUTO_INCREMENT) and PostgreSQL (the column's sequence) are supported.
 * MySQL's ALTER TABLE commits implicitly, so it is skipped (and reported) while a transaction is open.
 */
final class SequenceBumper
{
    /**
     * @param  list<string>  $tables
     * @param  array<string, int>  $floors  target table => highest SOURCE id it will ever receive: the sequence also
     *                                      moves past it, so a row added on this install between two partial runs
     *                                      can never take an id a later run still has to import
     */
    public static function bump(Connection $db, array $tables, int $gap, ImportReport $report, array $floors = []): void
    {
        $grammar = $db->getQueryGrammar();
        foreach ($tables as $table) {
            $max = max((int) $db->table($table)->max('id'), (int) ($floors[$table] ?? 0));
            if ($max <= 0) {
                continue;
            }
            $next = $max + $gap;
            switch ($db->getDriverName()) {
                case 'sqlite':
                    // next id = seq + 1
                    if ($db->table('sqlite_sequence')->where('name', $db->getTablePrefix().$table)->exists()) {
                        $db->table('sqlite_sequence')->where('name', $db->getTablePrefix().$table)->update(['seq' => $next - 1]);
                    } else {
                        $db->table('sqlite_sequence')->insert(['name' => $db->getTablePrefix().$table, 'seq' => $next - 1]);
                    }
                    break;
                case 'mysql':
                case 'mariadb':
                    if ($db->transactionLevel() > 0) {
                        $report->sequence($table, 'skipped: transaction open');

                        continue 2;
                    }
                    $db->statement('alter table '.$grammar->wrapTable($table).' auto_increment = '.$next);
                    break;
                case 'pgsql':
                    $sequence = $db->selectOne('select pg_get_serial_sequence(?, ?) as s', [$db->getTablePrefix().$table, 'id'])?->s;
                    if (! $sequence) {
                        $report->sequence($table, 'skipped: no sequence');

                        continue 2;
                    }
                    // setval(n) => nextval() returns n + 1
                    $db->statement('select setval(?, ?)', [$sequence, $next - 1]);
                    break;
                default:
                    $report->sequence($table, 'skipped: unsupported driver');

                    continue 2;
            }
            $report->sequence($table, $next);
        }
    }
}
