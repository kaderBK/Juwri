<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-09 V-12: phone-agent minutes go into the same cost ledger as WhatsApp/SMS/email, so `message_costs.channel`
 * also allows `voice`. PostgreSQL / MySQL: the named CHECK is replaced; SQLite (tests): the column is rebuilt with
 * the new enum CHECK (SQLite cannot alter a constraint in place).
 */
return new class extends Migration
{
    private const CHECK = 'message_costs_channel_check';

    public function up(): void
    {
        $this->replace(['whatsapp', 'sms', 'email', 'voice']);
    }

    public function down(): void
    {
        DB::table('message_costs')->where('channel', 'voice')->delete();
        $this->replace(['whatsapp', 'sms', 'email']);
    }

    /** @param  list<string>  $allowed */
    private function replace(array $allowed): void
    {
        $driver = DB::getDriverName();
        $values = implode(', ', array_map(fn ($v) => "'".$v."'", $allowed));
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE message_costs DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('ALTER TABLE message_costs ADD CONSTRAINT '.self::CHECK.' CHECK (channel IN ('.$values.'))');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (DB::selectOne('SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', ['message_costs', self::CHECK]) !== null) {
                DB::statement(\App\Support\MySqlDialect::dropCheckSql('message_costs', self::CHECK));
            }
            DB::statement('ALTER TABLE message_costs ADD CONSTRAINT '.self::CHECK.' CHECK (channel IN ('.$values.'))');
        } elseif ($driver === 'sqlite') {
            Schema::table('message_costs', function (Blueprint $table) use ($allowed) {
                $table->enum('channel', $allowed)->change();
            });
        }
    }
};
