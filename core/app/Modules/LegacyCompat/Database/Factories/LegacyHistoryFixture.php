<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Database\Factories;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * SYNTHETIC source tables for the importer's money/history tests (J19: no real data). Adds the later-lineage history tables
 * (variants, extras, order line extras, user wallet ledger, billing transactions and receipts) and the later snapshot columns to a source
 * built by LegacySourceFixture.
 */
final class LegacyHistoryFixture
{
    public function __construct(public readonly Connection $db) {}

    public function createSchema(): self
    {
        $s = $this->db->getSchemaBuilder();
        if (! $s->hasTable('menu_variants')) {
            $s->create('menu_variants', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('menu_id')->nullable();
                $t->decimal('price', 12, 3)->nullable();
                $t->longText('options')->nullable();
                $t->boolean('active')->default(true);
                $t->integer('position')->default(9999);
            });
        }
        if (! $s->hasTable('menu_extras')) {
            $s->create('menu_extras', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('menu_id')->nullable();
                $t->string('title')->nullable();
                $t->decimal('price', 10, 2)->nullable()->default(0);
                $t->integer('position')->default(9999);
                $t->boolean('active')->default(true);
                $t->longText('translations')->nullable();
            });
        }
        if (! $s->hasTable('order_item_extras')) {
            $s->create('order_item_extras', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('order_item_id')->nullable();
                $t->integer('extra_id')->nullable();
                $t->integer('quantity')->default(1);
            });
        }
        if (! $s->hasTable('wallet_transactions')) {
            $s->create('wallet_transactions', function (Blueprint $t) {
                $t->id();
                $t->integer('user_id');
                $t->decimal('amount', 10, 2);
                $t->char('currency_code', 3)->nullable();
                $t->decimal('balance_after', 10, 2);
                $t->string('type', 8)->default('credit');
                $t->string('reason', 120)->nullable();
                $t->string('ref', 80)->nullable();
                $t->integer('granted_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
        if (! $s->hasTable('transaction')) {
            $s->create('transaction', function (Blueprint $t) {
                $t->increments('id');
                $t->string('product_name', 225)->nullable();
                $t->integer('product_id')->nullable();
                $t->integer('user_id')->nullable();
                $t->string('status', 20)->nullable();
                $t->double('amount')->nullable();
                $t->double('base_amount')->nullable();
                $t->string('currency_code', 3)->nullable();
                $t->string('payment_id')->nullable();
                $t->string('transaction_gatway')->nullable();
                $t->string('transaction_method', 20)->nullable();
                $t->string('transaction_description')->nullable();
                $t->string('transaction_ip', 100)->nullable();
                $t->string('frequency', 16)->nullable();
                $t->text('billing')->nullable();
                $t->text('taxes_ids')->nullable();
                $t->text('details')->nullable();
                $t->text('coupon')->nullable();
                $t->string('invoice_number', 32)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
        if (! $s->hasTable('billing_receipts')) {
            $s->create('billing_receipts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('transaction_id')->nullable();
                $t->string('transaction_method', 20)->nullable();
                $t->string('product_name', 225)->nullable();
                $t->integer('product_id')->nullable();
                $t->integer('plan_id')->nullable();
                $t->string('addon_type')->nullable();
                $t->decimal('amount', 18, 4)->default(0);
                $t->decimal('base_amount', 18, 4)->nullable();
                $t->char('currency_code', 3)->nullable();
                $t->string('gateway')->nullable();
                $t->string('gateway_ref')->nullable();
                $t->text('details')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        return $this;
    }

    /**
     * The later source versions' stored prices and totals: a source built by LegacySourceFixture has none of these
     * columns, so they are added one by one (sqlite adds one column per ALTER).
     */
    public function addSnapshotColumns(): self
    {
        $s = $this->db->getSchemaBuilder();
        if (! $s->hasColumn('order_items', 'unit_price')) {
            $s->table('order_items', function (Blueprint $t) {
                $t->decimal('unit_price', 12, 3)->nullable();
            });
        }
        if (! $s->hasColumn('order_items', 'price_label')) {
            $s->table('order_items', function (Blueprint $t) {
                $t->string('price_label', 64)->nullable();
            });
        }
        if (! $s->hasColumn('orders', 'subtotal')) {
            $s->table('orders', function (Blueprint $t) {
                $t->decimal('subtotal', 14, 3)->nullable();
            });
        }
        if (! $s->hasColumn('orders', 'tax_total')) {
            $s->table('orders', function (Blueprint $t) {
                $t->decimal('tax_total', 14, 3)->nullable();
            });
        }
        if (! $s->hasColumn('orders', 'grand_total')) {
            $s->table('orders', function (Blueprint $t) {
                $t->decimal('grand_total', 14, 3)->nullable();
            });
        }
        if (! $s->hasColumn('orders', 'currency_code')) {
            $s->table('orders', function (Blueprint $t) {
                $t->char('currency_code', 3)->nullable();
            });
        }
        if (! $s->hasColumn('order_item_extras', 'price')) {
            $s->table('order_item_extras', function (Blueprint $t) {
                $t->decimal('price', 12, 3)->nullable();
            });
        }

        return $this;
    }

    /** @param array<string, mixed> $attrs */
    public function variant(int $id, int $menuId, float|string|null $price, array $attrs = []): int
    {
        return $this->insert('menu_variants', $attrs + ['id' => $id, 'menu_id' => $menuId, 'price' => $price, 'options' => '{}']);
    }

    /** @param array<string, mixed> $attrs */
    public function extra(int $id, int $menuId, float|string|null $price, array $attrs = []): int
    {
        return $this->insert('menu_extras', $attrs + ['id' => $id, 'menu_id' => $menuId, 'title' => 'Extra '.$id, 'price' => $price]);
    }

    /** @param array<string, mixed> $attrs */
    public function orderItemExtra(int $id, int $orderItemId, ?int $extraId, array $attrs = []): int
    {
        return $this->insert('order_item_extras', $attrs + ['id' => $id, 'order_item_id' => $orderItemId, 'extra_id' => $extraId, 'quantity' => 1]);
    }

    /** @param array<string, mixed> $attrs */
    public function walletEntry(int $id, int $userId, float|string $amount, float|string $balanceAfter, array $attrs = []): int
    {
        return $this->insert('wallet_transactions', $attrs + ['id' => $id, 'user_id' => $userId, 'amount' => $amount,
            'balance_after' => $balanceAfter, 'type' => ((float) $amount) < 0 ? 'debit' : 'credit', 'reason' => 'admin_grant',
            'ref' => 'ref-'.$id, 'created_at' => '2025-04-01 10:00:00', 'updated_at' => '2025-04-01 10:00:00']);
    }

    /** @param array<string, mixed> $attrs */
    public function billingTransaction(int $id, ?int $userId, float|string|null $amount, ?string $currency, array $attrs = []): int
    {
        return $this->insert('transaction', $attrs + ['id' => $id, 'user_id' => $userId, 'amount' => $amount, 'base_amount' => $amount,
            'currency_code' => $currency, 'status' => 'success', 'product_name' => 'Plan '.$id, 'product_id' => 1,
            'transaction_method' => 'membership', 'transaction_gatway' => 'stripe', 'payment_id' => 'pay-synthetic-'.$id,
            'frequency' => 'MONTHLY', 'created_at' => '2025-05-01 09:00:00', 'updated_at' => '2025-05-01 09:00:00']);
    }

    /** @param array<string, mixed> $attrs */
    public function billingReceipt(int $id, ?int $userId, ?int $transactionId, float|string $amount, ?string $currency, array $attrs = []): int
    {
        return $this->insert('billing_receipts', $attrs + ['id' => $id, 'user_id' => $userId, 'transaction_id' => $transactionId,
            'amount' => $amount, 'base_amount' => $amount, 'currency_code' => $currency, 'transaction_method' => 'membership',
            'product_name' => 'Plan '.$id, 'gateway' => 'stripe', 'gateway_ref' => 'pay-synthetic-'.$id,
            'created_at' => '2025-05-01 09:00:00', 'updated_at' => '2025-05-01 09:00:00']);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $row['id'];
    }
}
