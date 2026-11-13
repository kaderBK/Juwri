<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P3-10: the Bookings module (L3, Community) — staff × service × duration appointments.
 *
 * Conventions: bigint ids + UUIDv7 public_id on user-facing rows, *_minor money in the row's currency, dateTimeTz
 * (UTC), statuses as string + CHECK, account_id + store_id on every row (RLS policy from TenantTables).
 *
 * The double-booking guard is `booking_slot_cells`: every hold and booking writes one row per 5-minute cell of the
 * time it blocks (service time + buffers), and UNIQUE(staff_id, cell_at) makes an overlapping second insert fail on
 * every database — no application lock is needed for correctness. Cells of an expired hold (or of a booking whose
 * payment window ended) carry `expires_at` and are deleted before the next insert for that staff member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_staff', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->string('name', 80);
            $table->string('role', 60)->nullable();
            $table->string('photo_url', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['store_id', 'active'], 'booking_staff_store_id_active_index');
            $table->index(['account_id', 'store_id'], 'booking_staff_account_id_store_id_index');
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedSmallInteger('buffer_before_min')->default(0);
            $table->unsignedSmallInteger('buffer_after_min')->default(0);
            $table->bigInteger('price_minor')->default(0);
            $table->string('currency', 8);
            $table->checkedString('deposit_type', ['none', 'amount', 'percent'], 16)->default('none');
            $table->bigInteger('deposit_value')->default(0); // minor units (amount) or whole percent (percent)
            $table->boolean('venue')->default(true);
            $table->boolean('at_home')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['store_id', 'active'], 'booking_services_store_id_active_index');
            $table->index(['account_id', 'store_id'], 'booking_services_account_id_store_id_index');
        });

        Schema::create('booking_staff_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('staff_id')->constrained('booking_staff')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('booking_services')->cascadeOnDelete();
            $table->unique(['staff_id', 'service_id'], 'booking_staff_services_staff_id_service_id_unique');
            $table->index(['store_id', 'service_id'], 'booking_staff_services_store_id_service_id_index');
            $table->index(['account_id', 'store_id'], 'booking_staff_services_account_id_store_id_index');
        });

        // Weekly hours: rows with staff_id NULL are the store's; a staff member with rows of their own uses those.
        // `kind` open = working time, break = time off inside it. Times are store-local "HH:MM" (24 h).
        Schema::create('booking_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('staff_id')->nullable()->constrained('booking_staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday … 6 = Saturday (Carbon dayOfWeek)
            $table->checkedString('kind', ['open', 'break'], 8)->default('open');
            $table->char('starts_local', 5);
            $table->char('ends_local', 5);
            $table->index(['store_id', 'staff_id', 'weekday'], 'booking_hours_store_id_staff_id_weekday_index');
            $table->index(['account_id', 'store_id'], 'booking_hours_account_id_store_id_index');
        });

        Schema::create('booking_closed_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('staff_id')->nullable()->constrained('booking_staff')->cascadeOnDelete();
            $table->date('date');
            $table->string('note', 120)->nullable();
            $table->index(['store_id', 'date'], 'booking_closed_days_store_id_date_index');
            $table->index(['account_id', 'store_id'], 'booking_closed_days_account_id_store_id_index');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('staff_id')->constrained('booking_staff');
            $table->string('number', 16);
            $table->checkedString('status', ['pending_payment', 'confirmed', 'pending_approval', 'cancelled', 'completed', 'no_show'], 20)->default('confirmed');
            $table->checkedString('location', ['venue', 'at_home'], 8)->default('venue');
            $table->string('customer_name', 80);
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->dateTimeTz('block_starts_at');
            $table->dateTimeTz('block_ends_at');
            $table->unsignedSmallInteger('duration_min');
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('deposit_minor')->default(0);
            $table->string('currency', 8);
            $table->checkedString('deposit_status', ['none', 'pending', 'paid', 'refunded', 'kept', 'received_late'], 16)->default('none');
            $table->unsignedBigInteger('deposit_transaction_id')->nullable();
            $table->dateTimeTz('payment_expires_at')->nullable();
            $table->dateTimeTz('cancel_deadline_at');
            $table->string('manage_nonce', 32);
            $table->boolean('remind_email')->default(false);
            $table->boolean('remind_sms')->default(false);
            $table->boolean('remind_whatsapp')->default(false);
            $table->dateTimeTz('reminder_sent_at')->nullable();
            $table->bigInteger('no_show_fee_minor')->default(0);
            $table->string('source', 16)->default('web');
            $table->uuid('client_key')->nullable();
            $table->dateTimeTz('cancelled_at')->nullable();
            $table->string('cancelled_by', 16)->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['store_id', 'number'], 'bookings_store_id_number_unique');
            $table->unique(['store_id', 'client_key'], 'bookings_store_id_client_key_unique');
            $table->index(['store_id', 'starts_at'], 'bookings_store_id_starts_at_index');
            $table->index(['store_id', 'status'], 'bookings_store_id_status_index');
            $table->index(['status', 'starts_at'], 'bookings_status_starts_at_index');
            $table->index(['deposit_transaction_id'], 'bookings_deposit_transaction_id_index');
            $table->index(['account_id', 'store_id'], 'bookings_account_id_store_id_index');
        });

        Schema::create('booking_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('name', 120);
            $table->unsignedSmallInteger('duration_min');
            $table->bigInteger('price_minor')->default(0);
            $table->bigInteger('deposit_minor')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->index(['booking_id'], 'booking_lines_booking_id_index');
            $table->index(['account_id', 'store_id'], 'booking_lines_account_id_store_id_index');
        });

        Schema::create('booking_holds', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('staff_id')->constrained('booking_staff');
            $table->unsignedBigInteger('booking_id')->nullable(); // set for a reschedule hold and once booked
            $table->json('service_ids');
            $table->checkedString('location', ['venue', 'at_home'], 8)->default('venue');
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->dateTimeTz('block_starts_at');
            $table->dateTimeTz('block_ends_at');
            $table->dateTimeTz('expires_at');
            $table->char('token_hash', 64);
            $table->char('holder_key', 64)->nullable();
            $table->checkedString('status', ['active', 'used', 'released', 'expired'], 10)->default('active');
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['store_id', 'status', 'expires_at'], 'booking_holds_store_id_status_expires_at_index');
            $table->index(['holder_key', 'status'], 'booking_holds_holder_key_status_index');
            $table->index(['account_id', 'store_id'], 'booking_holds_account_id_store_id_index');
        });

        Schema::create('booking_slot_cells', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('staff_id')->constrained('booking_staff')->cascadeOnDelete();
            $table->dateTimeTz('cell_at');
            $table->unsignedBigInteger('hold_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->dateTimeTz('expires_at')->nullable();
            $table->unique(['staff_id', 'cell_at'], 'booking_slot_cells_staff_id_cell_at_unique');
            $table->index(['hold_id'], 'booking_slot_cells_hold_id_index');
            $table->index(['booking_id'], 'booking_slot_cells_booking_id_index');
            $table->index(['staff_id', 'expires_at'], 'booking_slot_cells_staff_id_expires_at_index');
            $table->index(['account_id', 'store_id'], 'booking_slot_cells_account_id_store_id_index');
        });

        // The RLS policies are generated from TenantTables; these tables came after the 900000 sync migration.
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        foreach (['booking_slot_cells', 'booking_holds', 'booking_lines', 'bookings', 'booking_closed_days', 'booking_hours', 'booking_staff_services', 'booking_services', 'booking_staff'] as $table) {
            RowLevelSecurity::dropTable(DB::connection(), $table);
            Schema::dropIfExists($table);
        }
    }
};
