<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * Offline POS, OP-12 (owner Q6 option A): each till is its own ZATCA EGS unit and signs simplified invoices on the
 * device. Gated by OP-0 (config pos.offline.zatca_device_signing, off by default).
 *
 * pos_offline_zatca_units: one row per onboarding of a till (a wiped till onboards again: the old row is retired). The
 * till's key pair stays on the device; the server keeps its public key, the CSIDs (secrets encrypted) and the chain
 * state it has checked: last_icv / last_hash.
 * pos_offline_zatca_invoices: every invoice a till signed, as uploaded after sync, with the result of the chain check
 * (ok | gap | fork) and of reporting to ZATCA (pending → reported | rejected). Issued invoices are always kept and
 * reported, whatever the chain says; the chain result is for the owner to review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_offline_zatca_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('pos_offline_device_id')->unsigned();
            $table->string('device_id', 64);
            $table->checkedString('status', ['compliance', 'production', 'retired'], 16)->default('compliance');
            $table->text('public_key');
            $table->string('compliance_request_id', 128);
            $table->text('compliance_certificate');
            $table->text('compliance_secret'); // encrypted
            $table->text('production_certificate')->nullable();
            $table->text('production_secret')->nullable(); // encrypted
            $table->bigInteger('last_icv')->unsigned()->default(0);
            $table->string('last_hash', 64)->nullable();
            $table->dateTimeTz('production_at')->nullable();
            $table->dateTimeTz('retired_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['store_id', 'pos_offline_device_id', 'status'], 'pos_offline_zatca_units_device_index');
            $table->index(['account_id', 'store_id'], 'pos_offline_zatca_units_account_id_store_id_index');
        });
        Schema::create('pos_offline_zatca_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('unit_id')->unsigned();
            $table->string('device_id', 64);
            $table->bigInteger('icv')->unsigned();
            $table->string('uuid', 64);
            $table->string('invoice_hash', 64);
            $table->string('previous_hash', 64);
            $table->longText('xml');
            $table->text('signature');
            $table->char('client_key', 36)->nullable();
            $table->checkedString('chain', ['ok', 'gap', 'fork'], 8);
            $table->checkedString('status', ['pending', 'reported', 'rejected'], 12)->default('pending');
            $table->integer('attempts')->default(0);
            $table->json('report_response')->nullable();
            $table->dateTimeTz('reported_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['unit_id', 'invoice_hash'], 'pos_offline_zatca_invoices_hash_unique');
            $table->index(['unit_id', 'icv'], 'pos_offline_zatca_invoices_icv_index');
            $table->index(['store_id', 'status'], 'pos_offline_zatca_invoices_status_index');
            $table->index(['account_id', 'store_id'], 'pos_offline_zatca_invoices_account_id_store_id_index');
        });
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'pos_offline_zatca_invoices');
        Schema::dropIfExists('pos_offline_zatca_invoices');
        RowLevelSecurity::dropTable(DB::connection(), 'pos_offline_zatca_units');
        Schema::dropIfExists('pos_offline_zatca_units');
    }
};
