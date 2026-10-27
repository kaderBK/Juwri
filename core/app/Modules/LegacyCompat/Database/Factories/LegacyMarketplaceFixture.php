<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Database\Factories;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * SYNTHETIC source tables for the importer's marketplace tests (no real data): shoppers, their wallet
 * ledger, addresses, store links, favourites and follows, marketplace page sections, and the tables the import skips.
 */
final class LegacyMarketplaceFixture
{
    public function __construct(public readonly Connection $db) {}

    public function createSchema(): self
    {
        $s = $this->db->getSchemaBuilder();

        if (! $s->hasTable('countries')) {
            $s->create('countries', function (Blueprint $t) {
                $t->increments('id');
                $t->string('code', 10);
                $t->string('name', 100);
            });
        }

        if (! $s->hasTable('shoppers')) {
            $s->create('shoppers', function (Blueprint $t) {
                $t->id();
                $t->string('phone', 32);
                $t->unsignedBigInteger('country_id');
                $t->unsignedBigInteger('reseller_id')->default(0);
                $t->string('name', 255)->nullable();
                $t->string('email', 255)->nullable();
                $t->string('locale', 10)->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->string('remember_token', 100)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['phone', 'country_id', 'reseller_id']);
            });
        }

        if (! $s->hasTable('shopper_wallet_ledger')) {
            $s->create('shopper_wallet_ledger', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shopper_id');
                $t->decimal('amount', 14, 3);
                $t->string('currency', 3);
                $t->string('type', 24);
                $t->string('ref_type', 32)->nullable();
                $t->unsignedBigInteger('ref_id')->nullable();
                $t->string('idempotency_key', 191)->unique();
                $t->string('reason', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->longText('meta')->nullable();
                $t->decimal('balance_after', 14, 3)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        if (! $s->hasTable('shopper_addresses')) {
            $s->create('shopper_addresses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shopper_id');
                $t->string('label', 60);
                $t->string('address', 500);
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->unsignedBigInteger('city_id')->nullable();
                $t->string('notes', 500)->nullable();
                $t->boolean('is_default')->default(false);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        if (! $s->hasTable('shopper_customer')) {
            $s->create('shopper_customer', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shopper_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('customer_id');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['shopper_id', 'restaurant_id']);
            });
        }

        if (! $s->hasTable('shopper_favorites')) {
            $s->create('shopper_favorites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shopper_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['shopper_id', 'restaurant_id']);
            });
        }

        if (! $s->hasTable('shopper_follows')) {
            $s->create('shopper_follows', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shopper_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['shopper_id', 'restaurant_id']);
            });
        }

        if (! $s->hasTable('shopper_push_subscriptions')) {
            $s->create('shopper_push_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shopper_id');
                $t->text('endpoint');
                $t->char('endpoint_hash', 64);
                $t->timestamps();
            });
        }

        if (! $s->hasTable('mk_sections')) {
            $s->create('mk_sections', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('reseller_id')->default(0);
                $t->unsignedBigInteger('city_id')->nullable();
                $t->string('type', 24);
                $t->longText('title')->nullable();
                $t->longText('config')->nullable();
                $t->unsignedInteger('position')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        /*
         * The tables the import skips: present in the source so the skip report has something to
         * report, but never loaded into the target.
         */
        if (! $s->hasTable('mk_dispatch_offers')) {
            $s->create('mk_dispatch_offers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id');
                $t->unsignedBigInteger('driver_id');
                $t->string('status', 16)->default('offered');
                $t->timestamps();
            });
        }

        if (! $s->hasTable('marketplace_media')) {
            $s->create('marketplace_media', function (Blueprint $t) {
                $t->id();
                $t->string('mediable_type', 255);
                $t->unsignedBigInteger('mediable_id');
                $t->string('disk_path', 255);
                $t->timestamps();
            });
        }

        if (! $s->hasTable('marketplace_reviews')) {
            $s->create('marketplace_reviews', function (Blueprint $t) {
                $t->id();
                $t->string('reviewable_type', 255);
                $t->unsignedBigInteger('reviewable_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedTinyInteger('rating');
                $t->timestamps();
            });
        }

        return $this;
    }

    public function country(int $id, string $code): int
    {
        return $this->insert('countries', ['id' => $id, 'code' => $code, 'name' => 'Country '.$code]);
    }

    /** @param array<string, mixed> $attrs */
    public function shopper(int $id, int $countryId, array $attrs = []): int
    {
        return $this->insert('shoppers', $attrs + ['id' => $id, 'country_id' => $countryId, 'phone' => '+10000000'.$id,
            'reseller_id' => 0, 'name' => 'Shopper '.$id, 'email' => 'shopper'.$id.'@example.test', 'locale' => 'en',
            'verified_at' => '2025-04-01 09:00:00', 'created_at' => '2025-04-01 09:00:00',
            'updated_at' => '2025-04-01 09:00:00']);
    }

    /** @param array<string, mixed> $attrs */
    public function walletEntry(int $id, int $shopperId, float|string $amount, string $currency, float|string|null $balanceAfter, array $attrs = []): int
    {
        return $this->insert('shopper_wallet_ledger', $attrs + ['id' => $id, 'shopper_id' => $shopperId,
            'amount' => $amount, 'currency' => $currency, 'balance_after' => $balanceAfter,
            'type' => ((float) $amount) < 0 ? 'spend' : 'topup', 'idempotency_key' => 'idem-'.$id,
            'reason' => 'synthetic', 'created_at' => '2025-04-02 10:00:00']);
    }

    /** @param array<string, mixed> $attrs */
    public function address(int $id, int $shopperId, array $attrs = []): int
    {
        return $this->insert('shopper_addresses', $attrs + ['id' => $id, 'shopper_id' => $shopperId,
            'label' => 'Home', 'address' => 'Street '.$id]);
    }

    public function storeLink(int $id, int $shopperId, int $storeId, int $customerId): int
    {
        return $this->insert('shopper_customer', ['id' => $id, 'shopper_id' => $shopperId,
            'restaurant_id' => $storeId, 'customer_id' => $customerId]);
    }

    public function favorite(int $id, int $shopperId, int $storeId): int
    {
        return $this->insert('shopper_favorites', ['id' => $id, 'shopper_id' => $shopperId,
            'restaurant_id' => $storeId]);
    }

    public function follow(int $id, int $shopperId, int $storeId): int
    {
        return $this->insert('shopper_follows', ['id' => $id, 'shopper_id' => $shopperId,
            'restaurant_id' => $storeId]);
    }

    /** @param array<string, mixed> $attrs */
    public function section(int $id, string $type, array $attrs = []): int
    {
        return $this->insert('mk_sections', $attrs + ['id' => $id, 'type' => $type,
            'title' => json_encode(['en' => 'Section '.$id]), 'config' => '{}']);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $row['id'];
    }
}
