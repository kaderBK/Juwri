<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4-22b (P6-38): a big switching-import commit runs on the queue — previewed → queued → running → committed | failed
 * (resumable from rows_done: every chunk and its rows_done move in one transaction). rows_total / rows_done / error
 * are the progress. Shipped as its own migration so installs that already ran 2026_10_08_140000 get the columns too;
 * every step is guarded, so fresh and upgraded installs end identical.
 * PostgreSQL / MySQL: the named status CHECK is replaced; SQLite (tests): the column is rebuilt with the new enum CHECK.
 */
return new class extends Migration
{
    private const CHECK = 'switch_imports_status_check';

    private const OLD = ['previewed', 'committed', 'discarded'];

    private const NEW = ['previewed', 'queued', 'running', 'committed', 'failed', 'discarded'];

    public function up(): void
    {
        Schema::table('switch_imports', function (Blueprint $table) {
            if (! Schema::hasColumn('switch_imports', 'rows_total')) {
                $table->unsignedInteger('rows_total')->default(0)->after('status');
            }
            if (! Schema::hasColumn('switch_imports', 'rows_done')) {
                $table->unsignedInteger('rows_done')->default(0)->after('rows_total');
            }
            if (! Schema::hasColumn('switch_imports', 'error')) {
                $table->string('error', 255)->nullable()->after('rows_done');
            }
        });
        $this->replaceCheck(self::NEW);
    }

    public function down(): void
    {
        DB::table('switch_imports')->whereIn('status', ['queued', 'running', 'failed'])->update(['status' => 'discarded']);
        $this->replaceCheck(self::OLD);
        foreach (['error', 'rows_done', 'rows_total'] as $column) {
            if (Schema::hasColumn('switch_imports', $column)) {
                Schema::table('switch_imports', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** @param  list<string>  $allowed */
    private function replaceCheck(array $allowed): void
    {
        $driver = DB::getDriverName();
        $values = implode(', ', array_map(fn ($v) => "'".$v."'", $allowed));
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE switch_imports DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('ALTER TABLE switch_imports ADD CONSTRAINT '.self::CHECK.' CHECK (status IN ('.$values.'))');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (DB::selectOne('SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', ['switch_imports', self::CHECK]) !== null) {
                DB::statement(\App\Support\MySqlDialect::dropCheckSql('switch_imports', self::CHECK));
            }
            DB::statement('ALTER TABLE switch_imports ADD CONSTRAINT '.self::CHECK.' CHECK (status IN ('.$values.'))');
        } elseif ($driver === 'sqlite') {
            Schema::table('switch_imports', function (Blueprint $table) use ($allowed) {
                $table->enum('status', $allowed)->default('previewed')->change();
            });
        }
    }
};
