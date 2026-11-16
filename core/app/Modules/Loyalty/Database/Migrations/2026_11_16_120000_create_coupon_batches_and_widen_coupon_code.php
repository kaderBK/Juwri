<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * Coupons C1: batches of generated coupon codes.
 *
 *  - `coupon_batches`: one row per generated batch, keyed to its issuer by the same dimensions as a coupon
 *    (user_id = store owner, reseller_id = reseller, neither = platform). A tenant table like `coupons`
 *    (TenantTables: store-less, user-owned, nullable account_id — platform/reseller batches have none); the
 *    BelongsToAccount fill sets account_id from the owner's account, and the RLS policy is synced below.
 *  - `coupons.batch_id`: nullable link to the batch (hand-made coupons have none).
 *  - `coupons.code` widened to 32 when it is narrower: bulk codes are `PREFIX-RANDOM` (prefix up to 12 + dash +
 *    up to 12). Never shortened; nullability and default are restated so `change()` keeps them. The per-issuer
 *    unique key (issuer_key, code_key — P6-215) is untouched; code_key is already 64 wide.
 *
 * Schema builder only (sqlite / MySQL / PostgreSQL). down() drops the batch link and table; the wider code column
 * is kept on purpose (narrowing could truncate codes written meanwhile).
 */
return new class extends Migration
{
    private const CODE_WIDTH = 32;

    public function up(): void
    {
        if (! Schema::hasTable('coupon_batches')) {
            Schema::create('coupon_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('name', 120)->nullable();
                $table->string('issuer_scope', 16); // platform | reseller | owner
                $table->unsignedBigInteger('reseller_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable(); // store owner
                $table->string('prefix', 12)->nullable();
                $table->unsignedInteger('count')->default(0);
                $table->jsonb('settings')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestampsTz();
                $table->index('account_id', 'coupon_batches_account_idx');
                $table->index('reseller_id', 'coupon_batches_reseller_idx');
                $table->index('user_id', 'coupon_batches_user_idx');
            });
        }

        if (! Schema::hasColumn('coupons', 'batch_id')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->index('batch_id', 'coupons_batch_idx');
            });
        }

        $this->widenCode();

        // The new tenant table gets its PostgreSQL policy (no-op on MySQL / SQLite or when RLS is off).
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        if (Schema::hasColumn('coupons', 'batch_id')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropIndex('coupons_batch_idx');
            });
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropColumn('batch_id');
            });
        }
        if (Schema::hasTable('coupon_batches')) {
            RowLevelSecurity::dropTable(DB::connection(), 'coupon_batches');
        }
        Schema::dropIfExists('coupon_batches');
    }

    /**
     * Widen coupons.code to CODE_WIDTH only when it is a length-limited string narrower than that. A text column,
     * a column without a declared length (SQLite does not enforce one) or one already wide enough is left alone.
     */
    private function widenCode(): void
    {
        $column = null;
        foreach (Schema::getColumns('coupons') as $c) {
            if (strtolower((string) $c['name']) === 'code') {
                $column = $c;
                break;
            }
        }
        if ($column === null) {
            return;
        }
        $type = strtolower((string) ($column['type'] ?? ''));
        if (! preg_match('/^(?:varchar|character varying|nvarchar|char|character)\s*\((\d+)\)/', $type, $m)) {
            return;
        }
        if ((int) $m[1] >= self::CODE_WIDTH) {
            return;
        }

        $nullable = (bool) ($column['nullable'] ?? false);
        $default = $this->defaultValue($column['default'] ?? null);

        Schema::table('coupons', function (Blueprint $table) use ($nullable, $default) {
            $def = $table->string('code', self::CODE_WIDTH)->nullable($nullable);
            if ($default !== null) {
                $def->default($default);
            }
            $def->change();
        });
    }

    /** The column default as a plain string (PostgreSQL reports `'abc'::character varying`), or null for none. */
    private function defaultValue(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = (string) $raw;
        if (strtoupper($raw) === 'NULL' || str_starts_with(strtoupper($raw), 'NULL::')) {
            return null;
        }
        if (preg_match("/^'(.*)'(?:::[a-z ]+)?$/s", $raw, $m)) {
            return str_replace("''", "'", $m[1]);
        }

        return $raw;
    }
};
