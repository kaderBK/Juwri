<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-20 slice 1 (J28): `store_domains` replaces `restaurant_domains`.
 *
 * One row per host a store answers on besides the implicit path form (`/{slug}` on the storefront host):
 *  - type `custom`    — the owner's own domain (menu.example.com); live only once verified (DNS proof).
 *  - type `subdomain` — a label under the platform storefront host ({label}.juwri.store); platform-controlled,
 *                       so it is created verified.
 * `host` is lower-case ASCII (IDN hosts are stored as punycode) and globally unique. At most one row per store
 * has `is_primary` (enforced by StoreDomains in a transaction under a store-row lock — a partial unique index is
 * not portable to MySQL); when none is primary, the store's primary address is the path form.
 *
 * Existing `restaurant_domains` rows are copied (verified → verified_at, the first verified domain of a store
 * becomes its primary, which is what effective_public_url() used as the canonical origin), then the old table is
 * dropped. Nothing is lost: down() recreates the old table and copies the custom rows back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->checkedString('type', ['subdomain', 'custom'], 20)->default('custom');
            $table->string('host', 255);
            $table->boolean('is_primary')->default(false);
            $table->dateTimeTz('verified_at')->nullable();
            $table->string('verification_token', 64)->nullable();
            $table->checkedString('ssl_status', ['pending', 'active', 'failed'], 20)->default('pending');
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['host'], 'store_domains_host_unique');
            $table->index(['store_id', 'is_primary'], 'store_domains_store_id_is_primary_index');
            $table->index(['account_id', 'store_id'], 'store_domains_account_id_store_id_index');
            $table->foreign(['store_id'], 'store_domains_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        if (Schema::hasTable('restaurant_domains')) {
            $primary = [];
            $stores = DB::table('stores')->pluck('id')->flip();
            DB::table('restaurant_domains')->orderBy('id')->each(function ($row) use (&$primary, $stores) {
                $host = strtolower(trim((string) $row->domain));
                if ($host === '' || ! isset($stores[$row->store_id]) || DB::table('store_domains')->where('host', $host)->exists()) {
                    return;
                }
                $verified = (bool) $row->verified;
                $isPrimary = $verified && ! isset($primary[$row->store_id]);
                if ($isPrimary) {
                    $primary[$row->store_id] = true;
                }
                DB::table('store_domains')->insert([
                    'account_id' => $row->account_id,
                    'store_id' => $row->store_id,
                    'type' => 'custom',
                    'host' => $host,
                    'is_primary' => $isPrimary,
                    'verified_at' => $verified ? ($row->updated_at ?? $row->created_at ?? now()) : null,
                    'verification_token' => $row->verification_token,
                    'ssl_status' => in_array($row->ssl_status, ['pending', 'active', 'failed'], true) ? $row->ssl_status : 'pending',
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });

            Schema::drop('restaurant_domains');
        }
    }

    public function down(): void
    {
        Schema::create('restaurant_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('store_id')->unsigned();
            $table->string('domain', 255);
            $table->boolean('verified')->default(false);
            $table->string('verification_token', 40);
            $table->checkedString('ssl_status', ['pending', 'active', 'failed'])->default('pending');
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->unique(['domain'], 'restaurant_domains_domain_unique');
            $table->index(['store_id'], 'restaurant_domains_store_id_index');
            $table->index(['account_id', 'store_id'], 'restaurant_domains_account_id_store_id_index');
        });

        if (Schema::hasTable('store_domains')) {
            DB::table('store_domains')->where('type', 'custom')->orderBy('id')->each(function ($row) {
                DB::table('restaurant_domains')->insert([
                    'account_id' => $row->account_id,
                    'store_id' => $row->store_id,
                    'domain' => $row->host,
                    'verified' => $row->verified_at !== null,
                    'verification_token' => substr((string) $row->verification_token, 0, 40),
                    'ssl_status' => $row->ssl_status,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
            Schema::drop('store_domains');
        }
    }
};
