<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PCI DSS 4.0 payment-page script controls. Platform tables (no tenant key): they describe the platform's own pages.
 *
 *  payment_page_checks  req. 11.6.1 — one row per check of one payment page as a customer's browser receives it: the
 *                       script set (src, SRI, content hash) and the security headers, their hashes, the outcome
 *                       against the last accepted baseline (`baseline` = 1 marks the known-good row of a page).
 *  payment_csp_reports  req. 6.4.3 — CSP violation reports the browsers send from payment pages (no IP address kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_page_checks')) {
            Schema::create('payment_page_checks', function (Blueprint $table) {
                $table->id();
                $table->string('page', 64);
                $table->string('url', 500);
                $table->string('status', 16); // baseline | unchanged | changed | error
                $table->json('scripts')->nullable();
                $table->unsignedInteger('inline_scripts')->default(0);
                $table->json('headers')->nullable();
                $table->string('script_hash', 64)->nullable();
                $table->string('header_hash', 64)->nullable();
                $table->json('changes')->nullable();
                $table->boolean('baseline')->default(false);
                $table->string('accepted_by', 120)->nullable();
                $table->dateTimeTz('accepted_at')->nullable();
                $table->string('error', 500)->nullable();
                $table->dateTimeTz('checked_at');
                $table->index(['page', 'baseline'], 'payment_page_checks_page_baseline_index');
                $table->index(['page', 'checked_at'], 'payment_page_checks_page_checked_index');
            });
        }

        if (! Schema::hasTable('payment_csp_reports')) {
            Schema::create('payment_csp_reports', function (Blueprint $table) {
                $table->id();
                $table->string('page', 64);
                $table->string('directive', 64)->nullable();
                $table->string('blocked_uri', 500)->nullable();
                $table->string('document_uri', 500)->nullable();
                $table->string('source_file', 500)->nullable();
                $table->unsignedInteger('line')->nullable();
                $table->string('disposition', 16)->nullable();
                $table->string('sample', 200)->nullable();
                $table->string('user_agent', 300)->nullable();
                $table->dateTimeTz('created_at');
                $table->index(['page', 'created_at'], 'payment_csp_reports_page_created_index');
                $table->index(['created_at'], 'payment_csp_reports_created_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_csp_reports');
        Schema::dropIfExists('payment_page_checks');
    }
};
