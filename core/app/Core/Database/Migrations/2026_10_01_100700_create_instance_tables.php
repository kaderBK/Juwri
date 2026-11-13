<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core — instance tables.
 *
 * P1-13 13e: instance tables a single self-hosted install needs — the abuse log, the audit trail (activity_logs),
 * platform notifications, platform settings, platform alerts, backup runs, store backups and their schedules — were
 * created by the Platform module's migration, which is ee since 13e. Same columns and indexes as before (P1-03
 * squashed schema). Idempotent: an install where the Platform migration already created them skips the create.
 * Timestamped before the tenant RLS migration (2026_10_01_900000) so a fresh PostgreSQL install protects them too.
 *
 * Tables: abuse_events, activity_logs, platform_notifications, platform_settings, platform_alerts, platform_backup_runs, store_backup_schedules, store_backups
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abuse_events')) {
            Schema::create('abuse_events', function (Blueprint $table) {
                $table->id();
                $table->string('guard', 40);
                $table->string('action', 60);
                $table->bigInteger('user_id')->unsigned()->nullable();
                $table->bigInteger('reseller_id')->unsigned()->nullable();
                $table->string('ip', 45)->nullable();
                $table->jsonb('meta')->nullable();
                $table->string('mode', 10)->default('warn');
                $table->dateTimeTz('created_at')->nullable();
                $table->index(['created_at'], 'abuse_events_created_at_index');
                $table->index(['guard'], 'abuse_events_guard_index');
                $table->index(['reseller_id'], 'abuse_events_reseller_id_index');
                $table->index(['user_id'], 'abuse_events_user_id_index');
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->bigInteger('actor_user_id')->unsigned()->nullable();
                $table->bigInteger('store_id')->unsigned()->nullable();
                $table->string('action', 80);
                $table->string('subject_type', 80)->nullable();
                $table->bigInteger('subject_id')->unsigned()->nullable();
                $table->jsonb('meta')->nullable();
                $table->string('ip', 45)->nullable();
                $table->dateTimeTz('created_at')->useCurrent();
                $table->index(['action'], 'activity_logs_action_index');
                $table->index(['actor_user_id', 'created_at'], 'activity_logs_actor_user_id_created_at_index');
                $table->index(['store_id', 'created_at'], 'activity_logs_store_id_created_at_index');
                $table->index(['account_id', 'store_id'], 'activity_logs_account_id_store_id_index');
            });
        }

        if (! Schema::hasTable('platform_notifications')) {
            Schema::create('platform_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('title', 255);
                $table->string('link', 255)->nullable();
                $table->string('type', 255);
                $table->boolean('status')->default(false);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['status'], 'notif_status_idx');
            });
        }

        if (! Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $table) {
                $table->id();
                $table->string('name', 191);
                $table->jsonb('value')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->unique(['name'], 'platform_settings_name_unique');
            });
        }

        if (! Schema::hasTable('platform_alerts')) {
            Schema::create('platform_alerts', function (Blueprint $table) {
                $table->id();
                $table->date('day');
                $table->string('kind', 60);
                $table->text('message');
                $table->dateTimeTz('resolved_at')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->index(['day'], 'platform_alerts_day_index');
                $table->index(['kind'], 'platform_alerts_kind_index');
            });
        }

        if (! Schema::hasTable('platform_backup_runs')) {
            Schema::create('platform_backup_runs', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 20);
                $table->boolean('ok')->default(false);
                $table->string('path', 190)->nullable();
                $table->bigInteger('size_bytes')->unsigned()->nullable();
                $table->integer('file_count')->unsigned()->nullable();
                $table->boolean('mirrored')->default(false);
                $table->string('mirror_error', 190)->nullable();
                $table->integer('duration_ms')->unsigned()->nullable();
                $table->string('detail', 190)->nullable();
                $table->text('error')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->index(['kind', 'id'], 'pbr_kind_idx');
            });
        }

        if (! Schema::hasTable('store_backup_schedules')) {
            Schema::create('store_backup_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->string('frequency', 10);
                $table->jsonb('datasets')->nullable();
                $table->string('format', 10)->default('both');
                $table->bigInteger('branch_id')->unsigned()->nullable();
                $table->string('orders_window', 10)->nullable();
                $table->string('notify_email', 255)->nullable();
                $table->boolean('active')->default(true);
                $table->dateTimeTz('next_run_at')->nullable();
                $table->dateTimeTz('last_run_at')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['next_run_at'], 'store_backup_schedules_next_run_at_index');
                $table->index(['store_id'], 'store_backup_schedules_store_id_index');
                $table->index(['account_id', 'store_id'], 'store_backup_schedules_account_id_store_id_index');
                $table->index(['user_id'], 'store_backup_schedules_user_id_index');
                $table->index(['branch_id'], 'store_backup_schedules_branch_id_index');
            });
        }

        if (! Schema::hasTable('store_backups')) {
            Schema::create('store_backups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->string('token', 64);
                $table->string('status', 20)->default('pending');
                $table->tinyInteger('progress')->unsigned()->default(0);
                $table->string('current_step', 255)->nullable();
                $table->boolean('include_pii')->default(false);
                $table->jsonb('datasets')->nullable();
                $table->string('format', 10)->default('both');
                $table->date('orders_from')->nullable();
                $table->date('orders_to')->nullable();
                $table->bigInteger('branch_id')->unsigned()->nullable();
                $table->boolean('encrypted')->default(false);
                $table->text('password_enc')->nullable();
                $table->string('notify_email', 255)->nullable();
                $table->bigInteger('schedule_id')->unsigned()->nullable();
                $table->string('path', 255)->nullable();
                $table->bigInteger('size_bytes')->unsigned()->nullable();
                $table->text('error')->nullable();
                $table->dateTimeTz('expires_at')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['store_id'], 'store_backups_store_id_index');
                $table->unique(['token'], 'store_backups_token_unique');
                $table->index(['user_id'], 'store_backups_user_id_index');
                $table->index(['account_id', 'store_id'], 'store_backups_account_id_store_id_index');
                $table->index(['branch_id'], 'store_backups_branch_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_backups');
        Schema::dropIfExists('store_backup_schedules');
        Schema::dropIfExists('platform_backup_runs');
        Schema::dropIfExists('platform_alerts');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('abuse_events');
    }
};
