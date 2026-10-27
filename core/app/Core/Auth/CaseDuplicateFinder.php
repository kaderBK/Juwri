<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-98: finds accounts whose email / username differ only in letter case. Such rows predate the case-insensitive
 * unique indexes (PG/SQLite compared case-sensitively before P6-40) and block that migration until an admin resolves
 * them. Scopes mirror the existing unique constraints: users and staff_members are global, customers are per store.
 * Values are never returned (PII) — only ids, dates and the group's scope.
 */
class CaseDuplicateFinder
{
    /** [table, column, scope column|null] */
    public const TARGETS = [
        ['users', 'email', null],
        ['users', 'username', null],
        ['staff_members', 'email', null],
        ['customers', 'email', 'store_id'],
    ];

    /**
     * @return list<array{table: string, column: string, scope: int|string|null, rows: list<array{id: int|string, created_at: mixed, last_login_at: mixed}>}>
     */
    public function find(): array
    {
        $groups = [];
        foreach (self::TARGETS as [$table, $column, $scope]) {
            if (! Schema::hasTable($table)) {
                continue; // module not installed
            }
            $key = $scope ? "{$scope}, lower({$column})" : "lower({$column})";
            $dupes = DB::table($table)
                ->selectRaw(($scope ? "{$scope} as scope, " : '')."lower({$column}) as k")
                ->whereNotNull($column)
                ->groupByRaw($key)
                ->havingRaw('count(*) > 1')
                ->get();
            if ($dupes->isEmpty()) {
                continue;
            }
            $login = Schema::hasColumn($table, 'last_login_at');
            foreach ($dupes as $dupe) {
                $rows = DB::table($table)
                    ->select(array_merge(['id', 'created_at'], $login ? ['last_login_at'] : []))
                    ->whereRaw("lower({$column}) = ?", [$dupe->k])
                    ->when($scope, fn ($q) => $q->where($scope, $dupe->scope))
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($r) => ['id' => $r->id, 'created_at' => $r->created_at, 'last_login_at' => $r->last_login_at ?? null])
                    ->all();
                $groups[] = ['table' => $table, 'column' => $column, 'scope' => $scope ? $dupe->scope : null, 'rows' => array_values($rows)];
            }
        }

        return $groups;
    }
}
