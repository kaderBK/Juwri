<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-13 13g: the Billing tables Community code reads, created without the Enterprise package — the feature catalogue (features), grants
 * and overrides with their audit log (EntitlementGates / EntitlementWriter, Core), the usage counters (TenantUsageDaily,
 * AccountUsage) and the legacy plan memberships (upgrades: User::upgrade, the admin account pages). Same columns and
 * indexes as the Billing migration had (P1-03 squashed schema); Schema::hasTable guards keep it a no-op where they
 * exist. Before Billing's migration (112000) and the tenant RLS migration (900000).
 *
 * Tables: entitlement_audit_logs, entitlement_overrides, feature_grants, features, tenant_usage_daily, account_usage, upgrades
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('entitlement_audit_logs')) {
            Schema::create('entitlement_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->bigInteger('user_id')->unsigned();
                $table->string('feature_key', 64);
                $table->string('action', 16);
                $table->string('source_type', 32)->default('admin');
                $table->bigInteger('source_id')->unsigned()->nullable();
                $table->bigInteger('actor_id')->unsigned()->nullable();
                $table->jsonb('before')->nullable();
                $table->jsonb('after')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['user_id'], 'entitlement_audit_logs_user_id_index');
                $table->index(['account_id'], 'entitlement_audit_logs_account_id_index');
            });
        }

        if (! Schema::hasTable('entitlement_overrides')) {
            Schema::create('entitlement_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->bigInteger('user_id')->unsigned();
                $table->string('feature_key', 64);
                $table->string('value', 32)->default('1');
                $table->dateTimeTz('expires_at')->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('source_type', 32)->default('admin');
                $table->bigInteger('source_id')->unsigned()->nullable();
                $table->bigInteger('granted_by')->unsigned()->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['source_type', 'source_id'], 'entitlement_overrides_source_type_source_id_index');
                $table->index(['user_id', 'feature_key'], 'entitlement_overrides_user_id_feature_key_index');
                $table->index(['user_id'], 'entitlement_overrides_user_id_index');
                $table->index(['account_id'], 'entitlement_overrides_account_id_index');
            });
        }

        if (! Schema::hasTable('feature_grants')) {
            Schema::create('feature_grants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->bigInteger('user_id')->unsigned();
                $table->string('feature_key', 64);
                $table->dateTimeTz('granted_at');
                $table->dateTimeTz('last_used_at')->nullable();
                $table->string('source', 16)->default('admin');
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['granted_at'], 'fg_granted_at_idx');
                $table->index(['last_used_at'], 'fg_last_used_idx');
                $table->unique(['user_id', 'feature_key'], 'fg_user_key_unq');
                $table->index(['account_id'], 'feature_grants_account_id_index');
            });
        }

        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64);
                $table->string('label', 255);
                $table->text('description')->nullable();
                $table->string('group_', 32)->default('general');
                $table->string('kind', 16)->default('boolean');
                $table->string('default_value', 32)->nullable();
                $table->jsonb('sellable_via')->nullable();
                $table->string('addon_sku', 64)->nullable();
                $table->boolean('platform_only')->default(false);
                $table->boolean('plan_gated')->default(false);
                $table->boolean('killable')->default(true);
                $table->boolean('shipped')->default(true);
                $table->string('tier', 16)->default('core');
                $table->string('upsell_title', 255)->nullable();
                $table->text('upsell_body')->nullable();
                $table->integer('position')->unsigned()->default(0);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->unique(['key'], 'features_key_unique');
            });
        }

        if (! Schema::hasTable('tenant_usage_daily')) {
            Schema::create('tenant_usage_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->date('date');
                $table->bigInteger('user_id')->unsigned();
                $table->bigInteger('reseller_id')->unsigned()->nullable();
                $table->string('metric', 40);
                $table->bigInteger('value')->default(0);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['date'], 'tenant_usage_daily_date_index');
                $table->unique(['date', 'user_id', 'metric'], 'tenant_usage_daily_date_user_id_metric_unique');
                $table->index(['user_id'], 'tenant_usage_daily_user_id_index');
                $table->index(['account_id'], 'tenant_usage_daily_account_id_index');
                $table->index(['reseller_id'], 'tenant_usage_daily_reseller_id_index');
            });
        }

        // P1-05 (P1-07 follow-up): running per-owner usage counters (was the dynamic platform setting
        // `abuse_storage_bytes_<user id>`). Keyed by the counted user (resellers/partners have no account);
        // account_id is filled from that user's account (TenantTables::USER_OWNED). period 'total' = running total.
        if (! Schema::hasTable('account_usage')) {
            Schema::create('account_usage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->bigInteger('user_id')->unsigned();
                $table->string('metric', 40);
                $table->string('period', 16)->default('total');
                $table->bigInteger('value')->default(0);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->unique(['user_id', 'metric', 'period'], 'account_usage_user_id_metric_period_unique');
                $table->index(['account_id', 'metric'], 'account_usage_account_id_metric_index');
            });
        }

        if (! Schema::hasTable('upgrades')) {
            Schema::create('upgrades', function (Blueprint $table) {
                $table->id('upgrade_id');
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('sub_id', 16)->default('0');
                $table->unsignedBigInteger('user_id')->default(0);
                $table->checkedString('pay_mode', ['one_time', 'recurring'])->default('one_time');
                $table->checkedString('interval', ['MONTHLY', 'YEARLY', 'LIFETIME'])->nullable();
                $table->bigInteger('upgrade_lasttime')->unsigned()->default(0);
                $table->bigInteger('upgrade_expires')->unsigned()->default(0);
                $table->dateTimeTz('trial_ends_at')->nullable();
                $table->boolean('trial_used')->default(false);
                $table->string('unique_id', 255)->nullable();
                $table->string('status', 255)->nullable();
                $table->dateTimeTz('grace_until')->nullable();
                $table->tinyInteger('retry_count')->unsigned()->default(0);
                $table->dateTimeTz('last_retry_at')->nullable();
                $table->index(['user_id'], 'upgrades_user_id_index');
                $table->index(['account_id'], 'upgrades_account_id_index');
                $table->foreign(['user_id'], 'upgrades_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrades');
        Schema::dropIfExists('account_usage');
        Schema::dropIfExists('tenant_usage_daily');
        Schema::dropIfExists('features');
        Schema::dropIfExists('feature_grants');
        Schema::dropIfExists('entitlement_overrides');
        Schema::dropIfExists('entitlement_audit_logs');
    }
};
