<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * P6-215: coupon codes were unique across ALL issuers (`coupons_code_unique`), so store B could not create "WELCOME10"
 * once store A had it, and the error leaked that the code exists elsewhere. Codes are now unique per issuer — an owner
 * (user_id), a reseller (reseller_id) or the platform — case-insensitively (as the P6-40 lookup matches).
 *
 * A plain unique(user_id, reseller_id, code) does not work: NULLs never collide in a unique index, and MySQL/MariaDB
 * cannot index LOWER(code) portably. So two derived columns, kept by the Coupon model on every save:
 *   - issuer_key  'u:<user_id>' | 'r:<reseller_id>' | 'p'
 *   - code_key    LOWER(TRIM(code))
 * unique(issuer_key, code_key) works the same on SQLite, MySQL/MariaDB and PostgreSQL.
 *
 * Backfill is data-preserving: codes are never changed. Existing rows are globally unique by exact code; on
 * case-sensitive drivers two rows of one issuer might still differ only in case — the later row's code_key gets a
 * '#<id>' suffix (it keeps resolving as before: resolve() matches on LOWER(code) and takes the first) and a warning is
 * logged, never a failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('issuer_key', 32)->nullable();
            $table->string('code_key', 64)->nullable();
        });

        $this->backfill();

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropUnique('coupons_code_unique');
        });
        Schema::table('coupons', function (Blueprint $table) {
            $table->unique(['issuer_key', 'code_key'], 'coupons_issuer_key_code_key_unique');
        });
    }

    /** Fill the keys of rows that have none yet (idempotent; public for the regression test). */
    public function backfill(): void
    {
        $seen = [];
        $collisions = 0;
        DB::table('coupons')->whereNotNull('code_key')->orderBy('id')
            ->each(function ($row) use (&$seen) {
                $seen[$row->issuer_key.'|'.$row->code_key] = true;
            });
        DB::table('coupons')->whereNull('code_key')->orderBy('id')->select(['id', 'user_id', 'reseller_id', 'code'])
            ->chunkById(500, function ($rows) use (&$seen, &$collisions) {
                foreach ($rows as $row) {
                    $issuer = $row->user_id ? 'u:'.$row->user_id : ($row->reseller_id ? 'r:'.$row->reseller_id : 'p');
                    $code = mb_strtolower(trim((string) $row->code));
                    if (isset($seen[$issuer.'|'.$code])) {
                        $collisions++;
                        $code .= '#'.$row->id;
                    }
                    $seen[$issuer.'|'.$code] = true;
                    DB::table('coupons')->where('id', $row->id)->update(['issuer_key' => $issuer, 'code_key' => $code]);
                }
            });

        if ($collisions > 0) {
            Log::warning('P6-215: coupons of one issuer differ only in case; later rows kept with a suffixed code_key', ['rows' => $collisions]);
        }
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropUnique('coupons_issuer_key_code_key_unique');
        });
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['issuer_key', 'code_key']);
        });
        Schema::table('coupons', function (Blueprint $table) {
            $table->unique(['code'], 'coupons_code_unique');
        });
    }
};
