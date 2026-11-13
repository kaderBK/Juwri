<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Database\Factories;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * SYNTHETIC source database for the importer tests (J19: no real data, ever). Builds tables SHAPED like the old
 * platform's (base schema + later-lineage tables, columns as the reference schema has them) on a throw-away
 * connection, and inserts rows with invented values. Kept in the module because the old table and column names may
 * only live here (J52b); tests call the neutral helper methods.
 *
 * Deliberate shape quirks the tests rely on: the items table carries one column no loader maps (unknown-column
 * report), the orders table has no `updated_at` (missing-column report), and a survey-answers table has no loader
 * (unmapped-table report).
 */
final class LegacySourceFixture
{
    public const UNKNOWN_ITEM_COLUMN = 'spice_note';

    public const UNMAPPED_TABLE = 'nps_responses';

    /** Owner column of the source store table. */
    public const STORE_OWNER_COLUMN = 'user_id';

    /** Source table of the old platform's store domains. */
    public const DOMAINS_TABLE = 'restaurant_domains';

    /** Source table of the old platform's resellers. */
    public const RESELLERS_TABLE = 'resellers';

    public function __construct(public readonly Connection $db) {}

    /** The source's per-store gateway option name for $gateway / $field. */
    public static function gatewayKey(string $gateway, string $field): string
    {
        return 'restaurant_'.$gateway.'_'.$field;
    }

    /** The source's store theme option name (renamed on import). */
    public const THEME_OPTION = 'restaurant_template';

    public function createSchema(): self
    {
        $s = $this->db->getSchemaBuilder();
        $s->create('user', function (Blueprint $t) {
            $t->increments('id');
            $t->string('user_type', 8)->default('user');
            $t->string('group_id', 16)->default('free');
            $t->string('username')->nullable();
            $t->string('email')->nullable();
            $t->string('name', 225)->nullable();
            $t->string('password')->nullable();
            $t->string('image', 225)->default('default_user.png');
            $t->float('balance')->default(0);
            $t->char('country_code', 50)->nullable();
            $t->string('country', 50)->nullable();
            $t->string('oauth_provider', 16)->nullable();
            $t->string('oauth_uid', 100)->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('remember_token', 100)->nullable();
            $t->boolean('status')->default(true);
            $t->dateTime('lastactive')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $s->create('options', function (Blueprint $t) {
            $t->increments('option_id');
            $t->string('option_name', 191)->nullable();
            $t->longText('option_value')->nullable();
        });
        $s->create('posts', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->string('slug')->nullable();
            $t->string('color', 10)->nullable();
            $t->string('title');
            $t->string('sub_title')->nullable();
            $t->text('description')->nullable();
            $t->string('main_image')->nullable();
            $t->string('cover_image')->nullable();
            $t->string('timing')->nullable();
            $t->string('address')->nullable();
            $t->string('phone', 20)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $s->create('post_options', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('post_id')->nullable();
            $t->string('option_name', 191)->nullable();
            $t->longText('option_value')->nullable();
        });
        $s->create('credentials', function (Blueprint $t) {
            $t->id();
            $t->integer('restaurant_id')->nullable();
            $t->string('feature');
            $t->string('provider');
            $t->text('secrets');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $s->create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('post_id');
            $t->string('name');
            $t->text('address')->nullable();
            $t->string('phone', 191)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->boolean('active')->default(true);
            $t->integer('position')->default(0);
            $t->timestamps();
        });
        $s->create('restaurant_tables', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('restaurant_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->integer('table_no')->nullable();
            $t->string('key')->nullable();
            $t->string('area_type', 16)->default('table');
            $t->string('label', 120)->nullable();
            $t->string('status', 20)->default('occupied');
            $t->string('verify_code', 8)->nullable();
        });
        $s->create('allergies', function (Blueprint $t) {
            $t->increments('id');
            $t->string('title')->nullable();
            $t->longText('translations')->nullable();
            $t->string('image')->default('default.png');
            $t->integer('position')->default(0);
            $t->string('active', 1)->default('1');
        });
        $s->create('menu_categories', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('restaurant_id')->nullable();
            $t->string('name')->nullable();
            $t->integer('parent')->nullable();
            $t->integer('position')->nullable()->default(999);
            $t->longText('translations')->nullable();
        });
        $s->create('menu', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('category_id')->nullable();
            $t->integer('restaurant_id')->nullable();
            $t->string('name')->nullable();
            $t->string('description')->nullable();
            $t->float('price')->nullable()->default(0);
            $t->string('image')->nullable()->default('default.png');
            $t->string('type', 8)->default('veg');
            $t->text('allergies')->nullable();
            $t->string('active', 1)->default('1');
            $t->integer('position')->default(9999);
            $t->longText('translations')->nullable();
            $t->string(self::UNKNOWN_ITEM_COLUMN)->nullable();
        });
        $s->create('customers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('restaurant_id');
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('password')->nullable();
            $t->boolean('marketing_consent')->default(false);
            $t->integer('orders_count')->default(0);
            $t->decimal('total_spent', 10, 2)->default(0);
            $t->timestamps();
        });
        $s->create('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('restaurant_id')->nullable();
            $t->string('type', 16)->default('on-table');
            $t->string('customer_name')->nullable();
            $t->integer('table_number')->nullable();
            $t->string('phone_number', 25)->nullable();
            $t->string('address')->nullable();
            $t->string('status', 16)->default('pending');
            $t->string('message')->nullable();
            $t->boolean('seen')->default(false);
            $t->boolean('is_paid')->default(false);
            $t->string('payment_gateway', 25)->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->decimal('tip_amount', 10, 2)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $s->create('order_items', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('order_id')->nullable();
            $t->integer('item_id')->nullable();
            $t->integer('variation')->nullable();
            $t->integer('quantity')->default(1);
        });
        $s->create('loyalty_programs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('restaurant_id');
            $t->string('type', 8)->default('stamps');
            $t->unsignedSmallInteger('earn_rate')->default(10);
            $t->unsignedSmallInteger('reward_threshold')->default(100);
            $t->string('reward_description', 200)->nullable();
            $t->decimal('reward_discount', 8, 2)->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $s->create('loyalty_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('restaurant_id');
            $t->integer('balance')->default(0);
            $t->integer('lifetime_earned')->default(0);
            $t->integer('lifetime_redeemed')->default(0);
            $t->timestamps();
        });
        $s->create('loyalty_ledger', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('loyalty_account_id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('type', 16)->default('earn');
            $t->integer('delta');
            $t->integer('balance_after');
            $t->string('note', 200)->nullable();
            $t->timestamps();
        });
        $s->create('gift_cards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('restaurant_id');
            $t->string('code', 32);
            $t->string('currency', 8);
            $t->decimal('initial_value', 15, 2);
            $t->string('status', 16)->default('active');
            $t->unsignedBigInteger('purchaser_customer_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->timestamps();
        });
        $s->create(self::DOMAINS_TABLE, function (Blueprint $t) {
            $t->increments('id');
            $t->integer('post_id')->nullable();
            $t->string('domain', 255)->nullable();
            $t->boolean('verified')->default(false);
            $t->string('verification_token', 40)->nullable();
            $t->string('ssl_status', 16)->default('pending');
            $t->timestamps();
        });
        $s->create(self::RESELLERS_TABLE, function (Blueprint $t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->string('name', 120)->nullable();
            $t->string('custom_domain', 120)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $s->create(self::UNMAPPED_TABLE, function (Blueprint $t) {
            $t->increments('id');
            $t->integer('restaurant_id')->nullable();
            $t->string('table_no')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        return $this;
    }

    /** @param array<string, mixed> $attrs */
    public function user(int $id, array $attrs = []): int
    {
        return $this->insert('user', $attrs + ['id' => $id, 'name' => 'User '.$id, 'username' => 'user'.$id, 'email' => "user{$id}@example.test",
            'password' => '$2y$10$syntheticsyntheticsyntheticsyntheticsyntheticsyntheti', 'created_at' => '2025-01-10 12:00:00']);
    }

    public function platformOption(string $name, ?string $value): void
    {
        $this->db->table('options')->insert(['option_name' => $name, 'option_value' => $value]);
    }

    /** @param array<string, mixed> $attrs */
    public function store(int $id, ?int $ownerId, array $attrs = []): int
    {
        return $this->insert('posts', $attrs + ['id' => $id, 'user_id' => $ownerId, 'slug' => 'store-'.$id, 'title' => 'Store '.$id,
            'created_at' => '2025-02-01 08:30:00', 'updated_at' => '2025-02-02 09:00:00']);
    }

    public function storeOption(int $storeId, string $name, ?string $value): void
    {
        $this->db->table('post_options')->insert(['post_id' => $storeId, 'option_name' => $name, 'option_value' => $value]);
    }

    /** A source per-store secrets row; $payload is the already-encrypted column value. */
    public function storeSecrets(int $storeId, string $feature, string $payload): void
    {
        $this->db->table('credentials')->insert(['restaurant_id' => $storeId, 'feature' => $feature, 'provider' => 'gateway', 'secrets' => $payload]);
    }

    /** @param array<string, mixed> $attrs */
    public function branch(int $id, int $storeId, array $attrs = []): int
    {
        return $this->insert('branches', $attrs + ['id' => $id, 'post_id' => $storeId, 'name' => 'Branch '.$id]);
    }

    /** @param array<string, mixed> $attrs */
    public function table(int $id, int $storeId, string $key, array $attrs = []): int
    {
        return $this->insert('restaurant_tables', $attrs + ['id' => $id, 'restaurant_id' => $storeId, 'table_no' => $id, 'key' => $key]);
    }

    /** @param array<string, mixed> $attrs */
    public function allergy(int $id, array $attrs = []): int
    {
        return $this->insert('allergies', $attrs + ['id' => $id, 'title' => 'Allergy '.$id]);
    }

    /** @param array<string, mixed> $attrs */
    public function category(int $id, int $storeId, array $attrs = []): int
    {
        return $this->insert('menu_categories', $attrs + ['id' => $id, 'restaurant_id' => $storeId, 'name' => 'Category '.$id]);
    }

    /** @param array<string, mixed> $attrs */
    public function item(int $id, int $storeId, ?int $categoryId, float|string|null $price, array $attrs = []): int
    {
        return $this->insert('menu', $attrs + ['id' => $id, 'restaurant_id' => $storeId, 'category_id' => $categoryId, 'name' => 'Item '.$id, 'price' => $price]);
    }

    /** @param array<string, mixed> $attrs */
    public function customer(int $id, int $storeId, array $attrs = []): int
    {
        return $this->insert('customers', $attrs + ['id' => $id, 'restaurant_id' => $storeId, 'name' => 'Customer '.$id, 'email' => "c{$id}@example.test"]);
    }

    /** @param array<string, mixed> $attrs */
    public function order(int $id, int $storeId, array $attrs = []): int
    {
        return $this->insert('orders', $attrs + ['id' => $id, 'restaurant_id' => $storeId, 'customer_name' => 'Guest '.$id, 'created_at' => '2025-03-01 20:15:00']);
    }

    /** @param array<string, mixed> $attrs */
    public function orderItem(int $id, int $orderId, ?int $itemId, int $quantity = 1, array $attrs = []): int
    {
        return $this->insert('order_items', $attrs + ['id' => $id, 'order_id' => $orderId, 'item_id' => $itemId, 'quantity' => $quantity]);
    }

    /** @param array<string, mixed> $attrs */
    public function loyaltyProgram(int $id, int $storeId, array $attrs = []): int
    {
        return $this->insert('loyalty_programs', $attrs + ['id' => $id, 'restaurant_id' => $storeId]);
    }

    /** @param array<string, mixed> $attrs */
    public function loyaltyAccount(int $id, int $storeId, int $customerId, array $attrs = []): int
    {
        return $this->insert('loyalty_accounts', $attrs + ['id' => $id, 'restaurant_id' => $storeId, 'customer_id' => $customerId]);
    }

    /** @param array<string, mixed> $attrs */
    public function ledger(int $id, int $loyaltyAccountId, int $delta, int $balanceAfter, array $attrs = []): int
    {
        return $this->insert('loyalty_ledger', $attrs + ['id' => $id, 'loyalty_account_id' => $loyaltyAccountId, 'delta' => $delta, 'balance_after' => $balanceAfter]);
    }

    /** @param array<string, mixed> $attrs */
    public function giftCard(int $id, int $storeId, string $code, string $currency, float|string $value, array $attrs = []): int
    {
        return $this->insert('gift_cards', $attrs + ['id' => $id, 'restaurant_id' => $storeId, 'code' => $code, 'currency' => $currency, 'initial_value' => $value]);
    }

    /** @param array<string, mixed> $attrs */
    public function domain(int $id, int $storeId, string $host, array $attrs = []): int
    {
        return $this->insert(self::DOMAINS_TABLE, $attrs + ['id' => $id, 'post_id' => $storeId, 'domain' => $host,
            'verified' => true, 'verification_token' => 'tok-'.$id, 'ssl_status' => 'active',
            'created_at' => '2025-02-01 08:30:00', 'updated_at' => '2025-02-02 09:00:00']);
    }

    /** @param array<string, mixed> $attrs */
    public function reseller(int $id, ?string $host, array $attrs = []): int
    {
        return $this->insert(self::RESELLERS_TABLE, $attrs + ['id' => $id, 'name' => 'Reseller '.$id,
            'custom_domain' => $host, 'active' => true]);
    }

    public function unmappedRow(int $id, int $storeId): int
    {
        return $this->insert(self::UNMAPPED_TABLE, ['id' => $id, 'restaurant_id' => $storeId, 'table_no' => '1']);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $row['id'];
    }
}
