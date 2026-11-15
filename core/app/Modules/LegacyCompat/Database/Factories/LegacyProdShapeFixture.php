<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Database\Factories;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * SYNTHETIC additions to {@see LegacySourceFixture} for the importer tests against the old platform's LATER schema
 * (J19: hand-written shapes, invented values, nothing taken from any real schema dump or row). Adds, on the same
 * throw-away connection, the later columns and tables the M4b maps read. Old names live only in this module (J52b).
 */
final class LegacyProdShapeFixture
{
    public function __construct(public readonly Connection $db) {}

    /**
     * Add a column DECLARED `timestamp` (an instant column of the source; a sqlite Blueprint would declare `datetime`).
     */
    public function instantColumn(string $table, string $column): self
    {
        $grammar = $this->db->getQueryGrammar();
        $this->db->statement('alter table '.$grammar->wrapTable($table).' add column '.$grammar->wrap($column).' timestamp null');

        return $this;
    }

    /**
     * Later catalogue and order columns (types as the later schema declares them; values in tests are invented):
     * soft-deleted items, item cost and dietary flags, per-order currency and money extras, the order's gift card /
     * coupon / tracking token, and weighed / recorded-price order lines.
     */
    public function laterColumns(): self
    {
        $this->addColumns('menu', [
            'deleted_at' => static fn (Blueprint $t) => $t->dateTime('deleted_at')->nullable(),
            'cost' => static fn (Blueprint $t) => $t->decimal('cost', 10, 3)->nullable(),
            'brand_id' => static fn (Blueprint $t) => $t->unsignedInteger('brand_id')->nullable(),
            'plu' => static fn (Blueprint $t) => $t->string('plu', 64)->nullable(),
            'pricing_unit' => static fn (Blueprint $t) => $t->string('pricing_unit', 10)->nullable(),
            'is_vegan' => static fn (Blueprint $t) => $t->boolean('is_vegan')->default(false),
            'is_sold_out' => static fn (Blueprint $t) => $t->boolean('is_sold_out')->default(false),
            'calories' => static fn (Blueprint $t) => $t->unsignedSmallInteger('calories')->nullable(),
            'auto_86_recipe' => static fn (Blueprint $t) => $t->boolean('auto_86_recipe')->default(false),
        ]);
        $this->addColumns('orders', [
            'currency_code' => static fn (Blueprint $t) => $t->char('currency_code', 3)->nullable(),
            'fx_rate_base' => static fn (Blueprint $t) => $t->decimal('fx_rate_base', 18, 8)->nullable(),
            'locale' => static fn (Blueprint $t) => $t->string('locale', 8)->nullable(),
            'coupon_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('coupon_id')->nullable(),
            'gift_card_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('gift_card_id')->nullable(),
            'gift_card_applied' => static fn (Blueprint $t) => $t->decimal('gift_card_applied', 15, 3)->default(0),
            'wallet_applied' => static fn (Blueprint $t) => $t->decimal('wallet_applied', 15, 3)->default(0),
            'mk_wallet_applied' => static fn (Blueprint $t) => $t->decimal('mk_wallet_applied', 14, 3)->default(0),
            'discount_amount' => static fn (Blueprint $t) => $t->double('discount_amount')->default(0),
            'track_token' => static fn (Blueprint $t) => $t->string('track_token', 32)->nullable(),
            'source' => static fn (Blueprint $t) => $t->string('source', 20)->default('qr'),
            'channel_fee' => static fn (Blueprint $t) => $t->decimal('channel_fee', 15, 3)->default(0),
            'service_charge_amount' => static fn (Blueprint $t) => $t->decimal('service_charge_amount', 10, 3)->nullable(),
            'donation_amount' => static fn (Blueprint $t) => $t->decimal('donation_amount', 10, 3)->default(0),
            'preparing_at' => static fn (Blueprint $t) => $t->dateTime('preparing_at')->nullable(),
            'pod_otp' => static fn (Blueprint $t) => $t->string('pod_otp', 8)->nullable(),
            'subtotal' => static fn (Blueprint $t) => $t->decimal('subtotal', 14, 3)->nullable(),
            'tax_total' => static fn (Blueprint $t) => $t->decimal('tax_total', 14, 3)->nullable(),
            'grand_total' => static fn (Blueprint $t) => $t->decimal('grand_total', 14, 3)->nullable(),
        ]);
        $this->addColumns('order_items', [
            'unit_price' => static fn (Blueprint $t) => $t->decimal('unit_price', 12, 3)->nullable(),
            'weight_kg' => static fn (Blueprint $t) => $t->decimal('weight_kg', 8, 3)->nullable(),
            'options' => static fn (Blueprint $t) => $t->longText('options')->nullable(),
            'status' => static fn (Blueprint $t) => $t->string('status', 16)->default('queued'),
            'seat' => static fn (Blueprint $t) => $t->unsignedSmallInteger('seat')->nullable(),
            'combo_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('combo_id')->nullable(),
            'price_schedule_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('price_schedule_id')->nullable(),
        ]);

        return $this;
    }

    /**
     * Later account / store / tenancy columns and the staff and room-type tables: per-store allergies, store vertical
     * and bio page, user reseller link / abuse hold / two-factor, customer referral fields, hotel room and server
     * columns on tables.
     */
    public function laterTenancy(): self
    {
        $this->addColumns('user', [
            'reseller_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('reseller_id')->nullable(),
            'two_factor_secret' => static fn (Blueprint $t) => $t->text('two_factor_secret')->nullable(),
            'two_factor_recovery_codes' => static fn (Blueprint $t) => $t->text('two_factor_recovery_codes')->nullable(),
            'two_factor_confirmed_at' => static fn (Blueprint $t) => $t->dateTime('two_factor_confirmed_at')->nullable(),
            'abuse_hold' => static fn (Blueprint $t) => $t->boolean('abuse_hold')->default(false),
            'login_landing' => static fn (Blueprint $t) => $t->string('login_landing', 10)->nullable(),
        ]);
        $this->addColumns('allergies', [
            'restaurant_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('restaurant_id')->nullable(),
        ]);
        $this->addColumns('posts', [
            'store_type' => static fn (Blueprint $t) => $t->string('store_type', 32)->default('restaurant'),
            'catalog_visible' => static fn (Blueprint $t) => $t->boolean('catalog_visible')->default(true),
            'bio_builder' => static fn (Blueprint $t) => $t->string('bio_builder', 8)->default('blocks'),
            'bio_html' => static fn (Blueprint $t) => $t->longText('bio_html')->nullable(),
        ]);
        $this->addColumns('customers', [
            'pending_password' => static fn (Blueprint $t) => $t->string('pending_password')->nullable(),
            'notifications_enabled' => static fn (Blueprint $t) => $t->boolean('notifications_enabled')->default(true),
            'system_tags' => static fn (Blueprint $t) => $t->longText('system_tags')->nullable(),
            'referral_code' => static fn (Blueprint $t) => $t->string('referral_code', 16)->nullable(),
            'referred_by_code' => static fn (Blueprint $t) => $t->string('referred_by_code', 16)->nullable(),
            'last_winback_at' => static fn (Blueprint $t) => $t->dateTime('last_winback_at')->nullable(),
        ]);
        $this->addColumns('restaurant_tables', [
            'server_staff_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('server_staff_id')->nullable(),
            'room_type_id' => static fn (Blueprint $t) => $t->unsignedBigInteger('room_type_id')->nullable(),
            'hk_status' => static fn (Blueprint $t) => $t->string('hk_status', 12)->default('clean'),
            'oo_status' => static fn (Blueprint $t) => $t->string('oo_status', 16)->default('none'),
            'oo_until' => static fn (Blueprint $t) => $t->date('oo_until')->nullable(),
        ]);
        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('room_types')) {
            $schema->create('room_types', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('name', 80);
                $t->string('code', 20)->nullable();
                $t->decimal('base_rate', 12, 3)->default(0);
                $t->integer('max_occupancy')->default(2);
                $t->integer('sort')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('restaurant_staff')) {
            $schema->create('restaurant_staff', static function (Blueprint $t): void {
                $t->id();
                $t->integer('owner_id');
                $t->integer('restaurant_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('name', 80);
                $t->string('email', 120);
                $t->string('password', 120);
                $t->string('pin', 8)->nullable();
                $t->string('pin_hash')->nullable();
                $t->string('role', 16)->default('waiter');
                $t->decimal('hourly_rate', 15, 3)->nullable();
                $t->longText('permissions')->nullable();
                $t->boolean('active')->default(true);
                $t->string('national_id', 40)->nullable();
                $t->date('date_of_birth')->nullable();
                $t->text('two_factor_secret')->nullable();
                $t->dateTime('two_factor_confirmed_at')->nullable();
                $t->timestamps();
            });
        }

        return $this;
    }

    /**
     * Later store-content, consent, coupon, review, combo and price-schedule tables (synthetic shapes for the
     * importer tests).
     */
    public function laterContent(): self
    {
        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('slug_redirects')) {
            $schema->create('slug_redirects', static function (Blueprint $t): void {
                $t->id();
                $t->string('old_slug', 255);
                $t->unsignedBigInteger('post_id');
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('sms_optouts')) {
            $schema->create('sms_optouts', static function (Blueprint $t): void {
                $t->id();
                $t->string('phone', 32);
                $t->unsignedBigInteger('restaurant_id')->nullable();
                $t->string('source', 40)->default('reply');
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('whatsapp_optouts')) {
            $schema->create('whatsapp_optouts', static function (Blueprint $t): void {
                $t->id();
                $t->string('phone', 32);
                $t->unsignedBigInteger('restaurant_id')->nullable();
                $t->string('source', 12)->default('reply');
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('whatsapp_consent')) {
            $schema->create('whatsapp_consent', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('phone', 32);
                $t->string('source', 12)->default('order');
                $t->dateTime('opted_in_at')->nullable();
                $t->dateTime('opted_out_at')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('account_deletions')) {
            $schema->create('account_deletions', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('status', 16)->default('pending');
                $t->dateTime('requested_at');
                $t->dateTime('purge_after');
                $t->dateTime('restored_at')->nullable();
                $t->dateTime('purged_at')->nullable();
                $t->string('email_hash', 64)->nullable();
                $t->string('reason', 500)->nullable();
                $t->string('requested_ip', 45)->nullable();
                $t->string('source', 16)->default('web');
                $t->integer('previous_status')->default(1);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('coupons')) {
            $schema->create('coupons', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('issuer_scope', 16)->default('owner');
                $t->unsignedBigInteger('reseller_id')->nullable();
                $t->string('applies_to', 16)->default('food_order');
                $t->decimal('min_order_amount', 10, 3)->nullable();
                $t->integer('per_customer_limit')->nullable();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('code', 32);
                $t->bigInteger('percentage')->default(1);
                $t->bigInteger('limit')->default(1);
                $t->bigInteger('used')->default(0);
                $t->dateTime('expiry_at')->nullable();
                $t->string('coupon_type', 20)->default('percentage');
                $t->string('applies_interval', 12)->default('any');
                $t->boolean('first_period_only')->default(false);
                $t->string('buy_scope', 16)->nullable();
                $t->unsignedBigInteger('buy_id')->nullable();
                $t->integer('buy_qty')->nullable();
                $t->unsignedBigInteger('get_id')->nullable();
                $t->integer('get_qty')->nullable();
                $t->decimal('get_discount_percent', 6, 3)->nullable();
                $t->unsignedBigInteger('batch_id')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('coupon_redemptions')) {
            $schema->create('coupon_redemptions', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('coupon_id');
                $t->unsignedBigInteger('customer_id');
                $t->integer('seq');
                $t->unsignedBigInteger('order_id')->nullable();
                $t->dateTime('created_at')->nullable();
            });
        }
        if (! $schema->hasTable('customer_addresses')) {
            $schema->create('customer_addresses', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('customer_id');
                $t->string('label', 255)->nullable();
                $t->string('line', 255)->nullable();
                $t->string('city', 255)->nullable();
                $t->string('state', 255)->nullable();
                $t->string('zip', 255)->nullable();
                $t->string('country', 255)->nullable();
                $t->decimal('lat', 10, 8)->nullable();
                $t->decimal('lng', 11, 8)->nullable();
                $t->boolean('is_default')->default(false);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('restaurant_follows')) {
            $schema->create('restaurant_follows', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('customer_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->boolean('notify')->default(true);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('media_assets')) {
            $schema->create('media_assets', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('filename', 255);
                $t->string('original_name', 255)->nullable();
                $t->string('folder', 40)->nullable();
                $t->string('source', 20)->nullable();
                $t->integer('width')->nullable();
                $t->integer('height')->nullable();
                $t->unsignedBigInteger('size_bytes')->nullable();
                $t->longText('attribution')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('menu_reviews')) {
            $schema->create('menu_reviews', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('menu_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('order_id')->nullable();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->string('reviewer_name', 80)->nullable();
                $t->integer('stars')->default(5);
                $t->text('body')->nullable();
                $t->string('status', 16)->default('pending');
                $t->boolean('is_verified')->default(false);
                $t->string('session_hash', 64)->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('menu_review_media')) {
            $schema->create('menu_review_media', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('review_id');
                $t->unsignedBigInteger('media_asset_id');
                $t->integer('position')->default(0);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('store_reviews')) {
            $schema->create('store_reviews', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->unsignedBigInteger('order_id')->nullable();
                $t->string('reviewer_name', 80)->nullable();
                $t->integer('stars')->default(5);
                $t->longText('criteria')->nullable();
                $t->text('body')->nullable();
                $t->string('status', 16)->default('pending');
                $t->string('session_hash', 64)->nullable();
                $t->boolean('is_verified')->default(false);
                $t->integer('helpful_count')->default(0);
                $t->text('owner_reply')->nullable();
                $t->dateTime('owner_replied_at')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('store_review_media')) {
            $schema->create('store_review_media', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('review_id');
                $t->unsignedBigInteger('media_asset_id');
                $t->integer('position')->default(0);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('stories')) {
            $schema->create('stories', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('media_url', 255);
                $t->string('caption', 255)->nullable();
                $t->string('cta_label', 255)->nullable();
                $t->string('cta_url', 255)->nullable();
                $t->integer('sort')->default(0);
                $t->dateTime('starts_at')->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('theme_settings')) {
            $schema->create('theme_settings', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('post_id');
                $t->string('template', 60);
                $t->unsignedBigInteger('theme_version_id')->nullable();
                $t->longText('settings');
                $t->longText('draft')->nullable();
                $t->longText('layout')->nullable();
                $t->longText('layout_draft')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('store_custom_blocks')) {
            $schema->create('store_custom_blocks', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('post_id');
                $t->string('name', 120);
                $t->longText('design_json')->nullable();
                $t->longText('html')->nullable();
                $t->longText('published_html')->nullable();
                $t->string('locale', 12)->nullable();
                $t->string('placement', 20)->nullable();
                $t->string('status', 20)->default('draft');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('bio_blocks')) {
            $schema->create('bio_blocks', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('post_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('type', 32);
                $t->string('label', 191)->nullable();
                $t->text('url')->nullable();
                $t->longText('config')->nullable();
                $t->integer('position')->default(0);
                $t->boolean('active')->default(true);
                $t->dateTime('visible_from')->nullable();
                $t->dateTime('visible_until')->nullable();
                $t->integer('clicks_cached')->default(0);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('qr_designs')) {
            $schema->create('qr_designs', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('name', 80)->default('Default');
                $t->boolean('is_default')->default(true);
                $t->string('preset', 32)->nullable();
                $t->string('fg_color', 9)->default('#0a0a0a');
                $t->string('bg_color', 9)->default('#ffffff');
                $t->string('eye_color', 9)->nullable();
                $t->string('gradient_to', 9)->nullable();
                $t->string('gradient_type', 8)->default('linear');
                $t->string('dot_style', 12)->default('square');
                $t->string('eye_style', 12)->default('square');
                $t->string('logo_file', 191)->nullable();
                $t->integer('logo_scale')->default(20);
                $t->string('frame', 12)->default('none');
                $t->string('frame_text', 60)->nullable();
                $t->integer('size')->default(512);
                $t->integer('quiet_zone')->default(4);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('image_menus')) {
            $schema->create('image_menus', static function (Blueprint $t): void {
                $t->increments('id');
                $t->integer('restaurant_id')->nullable();
                $t->string('name', 255)->nullable();
                $t->string('image', 255)->nullable();
                $t->string('active', 1)->default('1');
                $t->integer('position')->default(9999);
            });
        }
        if (! $schema->hasTable('branch_menu')) {
            $schema->create('branch_menu', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('menu_id');
                $t->boolean('available')->default(true);
                $t->decimal('price', 12, 3)->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('combos')) {
            $schema->create('combos', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('name', 190);
                $t->text('description')->nullable();
                $t->string('image', 190)->nullable();
                $t->decimal('price', 12, 3)->default(0);
                $t->boolean('active')->default(true);
                $t->integer('position')->default(0);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('combo_slots')) {
            $schema->create('combo_slots', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('combo_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->string('title', 190);
                $t->integer('min_select')->default(1);
                $t->integer('max_select')->default(1);
                $t->integer('position')->default(0);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('combo_slot_options')) {
            $schema->create('combo_slot_options', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('combo_slot_id');
                $t->unsignedBigInteger('combo_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('menu_id');
                $t->unsignedBigInteger('variant_id')->nullable();
                $t->decimal('upcharge', 12, 3)->default(0);
                $t->boolean('active')->default(true);
                $t->integer('position')->default(0);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('price_schedules')) {
            $schema->create('price_schedules', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('name', 80);
                $t->string('label', 60)->nullable();
                $t->longText('label_translations')->nullable();
                $t->string('scope', 16)->default('store');
                $t->string('adjustment_type', 16)->default('percent');
                $t->decimal('adjustment_value', 12, 3)->default(0);
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->string('days', 32)->nullable();
                $t->time('starts_at')->nullable();
                $t->time('ends_at')->nullable();
                $t->string('order_types', 64)->nullable();
                $t->integer('priority')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('price_schedule_targets')) {
            $schema->create('price_schedule_targets', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('schedule_id');
                $t->string('target_type', 16);
                $t->unsignedBigInteger('target_id');
            });
        }

        return $this;
    }

    /**
     * Later billing, hotel, reservation, catering, platform content, push, storefront key and view tables (synthetic
     * shapes for the importer tests).
     */
    public function laterScope(): self
    {
        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('subscriptions')) {
            $schema->create('subscriptions', static function (Blueprint $t): void {
                $t->id();
                $t->integer('user_id');
                $t->unsignedBigInteger('plan_id')->nullable();
                $t->string('status', 16);
                $t->string('interval_', 12);
                $t->tinyInteger('interval_count')->default(1);
                $t->dateTime('starts_at');
                $t->dateTime('ends_at')->nullable();
                $t->tinyInteger('anchor_day')->nullable();
                $t->dateTime('canceled_at')->nullable();
                $t->dateTime('grace_until')->nullable();
                $t->unsignedBigInteger('pending_plan_id')->nullable();
                $t->string('pending_interval', 12)->nullable();
                $t->integer('transaction_id')->nullable();
                $t->longText('meta')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('upgrades')) {
            $schema->create('upgrades', static function (Blueprint $t): void {
                $t->increments('upgrade_id');
                $t->string('sub_id', 16)->default('0');
                $t->integer('user_id')->default(0);
                $t->string('pay_mode', 12)->default('one_time');
                $t->string('interval', 12)->nullable();
                $t->unsignedBigInteger('upgrade_lasttime')->default(0);
                $t->unsignedBigInteger('upgrade_expires')->default(0);
                $t->dateTime('trial_ends_at')->nullable();
                $t->boolean('trial_used')->default(false);
                $t->string('unique_id', 255)->nullable();
                $t->string('status', 255)->nullable();
                $t->dateTime('grace_until')->nullable();
                $t->tinyInteger('retry_count')->default(0);
                $t->dateTime('last_retry_at')->nullable();
            });
        }
        if (! $schema->hasTable('user_addons')) {
            $schema->create('user_addons', static function (Blueprint $t): void {
                $t->id();
                $t->integer('user_id');
                $t->string('type', 60);
                $t->string('kind', 16)->default('count');
                $t->string('feature_key', 64)->nullable();
                $t->integer('quantity')->default(1);
                $t->decimal('quoted_price', 10, 2)->nullable();
                $t->string('pay_mode', 20)->default('one_time');
                $t->dateTime('expires_at')->nullable();
                $t->dateTime('trial_ends_at')->nullable();
                $t->string('transaction_ref', 80)->nullable();
                $t->string('unique_id', 255)->nullable();
                $t->boolean('auto_renew')->default(false);
                $t->string('status', 20)->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('rate_plans')) {
            $schema->create('rate_plans', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('name', 80);
                $t->unsignedBigInteger('parent_id')->nullable();
                $t->string('modifier_type', 12)->nullable();
                $t->decimal('modifier_value', 12, 3)->nullable();
                $t->boolean('refundable')->default(true);
                $t->integer('cancel_before_hours')->default(24);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('room_rates')) {
            $schema->create('room_rates', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('room_type_id');
                $t->unsignedBigInteger('rate_plan_id')->nullable();
                $t->date('date');
                $t->decimal('price', 12, 3);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('hotel_bookings')) {
            $schema->create('hotel_bookings', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('code', 12);
                $t->string('token', 64)->nullable();
                $t->string('guest_name', 120);
                $t->string('guest_phone', 40)->nullable();
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->unsignedBigInteger('room_type_id');
                $t->unsignedBigInteger('room_id')->nullable();
                $t->unsignedBigInteger('rate_plan_id')->nullable();
                $t->date('check_in');
                $t->date('check_out');
                $t->integer('adults')->default(2);
                $t->integer('children')->default(0);
                $t->string('status', 16)->default('pending');
                $t->decimal('quoted_total', 12, 3)->nullable();
                $t->string('currency', 3)->nullable();
                $t->string('source', 16)->default('desk');
                $t->unsignedBigInteger('hotel_stay_id')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('hotel_booking_nights')) {
            $schema->create('hotel_booking_nights', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('booking_id');
                $t->unsignedBigInteger('room_id');
                $t->date('night');
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('hotel_stays')) {
            $schema->create('hotel_stays', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->unsignedBigInteger('table_id');
                $t->string('guest_name', 120);
                $t->string('guest_phone', 40)->nullable();
                $t->string('status', 16)->default('checked_in');
                $t->dateTime('checked_in_at')->nullable();
                $t->dateTime('checked_out_at')->nullable();
                $t->unsignedBigInteger('folio_account_id')->nullable();
                $t->string('token', 64)->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('reservations')) {
            $schema->create('reservations', static function (Blueprint $t): void {
                $t->id();
                $t->integer('restaurant_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('name', 80);
                $t->string('phone', 30)->nullable();
                $t->string('email', 120)->nullable();
                $t->integer('party_size')->default(1);
                $t->decimal('deposit_amount', 15, 3)->default(0);
                $t->string('deposit_status', 16)->default('none');
                $t->unsignedBigInteger('deposit_transaction_id')->nullable();
                $t->string('currency', 8)->nullable();
                $t->dateTime('reserved_at');
                $t->string('table_number', 20)->nullable();
                $t->string('status', 16)->default('pending');
                $t->text('notes')->nullable();
                $t->string('token', 64)->nullable();
                $t->string('rwg_booking_id', 64)->nullable();
                $t->string('source', 16)->default('store');
                $t->timestamps();
                $t->unsignedBigInteger('table_id')->nullable();
            });
        }
        if (! $schema->hasTable('catering_requests')) {
            $schema->create('catering_requests', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('restaurant_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('name', 120);
                $t->string('phone', 40)->nullable();
                $t->string('email', 191)->nullable();
                $t->dateTime('event_at');
                $t->integer('headcount')->default(1);
                $t->text('message')->nullable();
                $t->longText('items')->nullable();
                $t->string('status', 16)->default('requested');
                $t->string('token', 64)->nullable();
                $t->decimal('quote_total', 15, 3)->nullable();
                $t->text('quote_note')->nullable();
                $t->unsignedBigInteger('order_id')->nullable();
                $t->decimal('deposit_amount', 15, 3)->nullable();
                $t->string('deposit_status', 16)->nullable();
                $t->unsignedBigInteger('deposit_transaction_id')->nullable();
                $t->string('currency', 8)->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('blog')) {
            $schema->create('blog', static function (Blueprint $t): void {
                $t->increments('id');
                $t->integer('author')->nullable();
                $t->string('title', 255)->nullable();
                $t->string('slug', 255)->nullable();
                $t->string('locale', 8)->nullable();
                $t->string('translation_group', 40)->nullable();
                $t->text('description')->nullable();
                $t->string('meta_description', 500)->nullable();
                $t->string('image', 255)->nullable();
                $t->text('tags')->nullable();
                $t->string('status', 12)->default('publish');
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
        if (! $schema->hasTable('blog_categories')) {
            $schema->create('blog_categories', static function (Blueprint $t): void {
                $t->increments('id');
                $t->string('title', 50)->nullable();
                $t->string('slug', 50)->nullable();
                $t->integer('position')->default(0);
                $t->string('active', 1)->default('1');
            });
        }
        if (! $schema->hasTable('blog_cat_relation')) {
            $schema->create('blog_cat_relation', static function (Blueprint $t): void {
                $t->increments('id');
                $t->integer('blog_id')->nullable();
                $t->integer('category_id')->nullable();
            });
        }
        if (! $schema->hasTable('pages')) {
            $schema->create('pages', static function (Blueprint $t): void {
                $t->increments('id');
                $t->string('translation_lang', 10)->nullable();
                $t->string('type', 1)->default('0');
                $t->string('builder', 16)->default('classic');
                $t->string('slug', 100)->nullable();
                $t->string('title', 200)->nullable();
                $t->longText('content')->nullable();
                $t->longText('design_json')->nullable();
                $t->boolean('active')->nullable()->default(true);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('faq_entries')) {
            $schema->create('faq_entries', static function (Blueprint $t): void {
                $t->increments('id');
                $t->string('faq_title', 255)->nullable();
                $t->text('faq_content')->nullable();
                $t->string('translation_lang', 10)->nullable();
                $t->boolean('active')->nullable()->default(true);
            });
        }
        if (! $schema->hasTable('testimonials')) {
            $schema->create('testimonials', static function (Blueprint $t): void {
                $t->id();
                $t->string('name', 255)->nullable();
                $t->string('designation', 255)->nullable();
                $t->longText('content');
                $t->string('image', 255)->nullable();
                $t->longText('translations')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('announcements')) {
            $schema->create('announcements', static function (Blueprint $t): void {
                $t->id();
                $t->string('title', 255);
                $t->text('body')->nullable();
                $t->string('type', 255)->default('info');
                $t->string('link_url', 255)->nullable();
                $t->string('link_label', 255)->nullable();
                $t->boolean('active')->default(true);
                $t->date('starts_at')->nullable();
                $t->date('ends_at')->nullable();
                $t->string('target', 255)->default('all');
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('languages')) {
            $schema->create('languages', static function (Blueprint $t): void {
                $t->increments('id');
                $t->string('code', 10)->nullable();
                $t->string('direction', 3)->nullable();
                $t->string('name', 100)->nullable();
                $t->boolean('active')->default(true);
                $t->integer('position')->default(999);
            });
        }
        if (! $schema->hasTable('customer_push_subscriptions')) {
            $schema->create('customer_push_subscriptions', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('order_id');
                $t->unsignedBigInteger('restaurant_id');
                $t->text('endpoint');
                $t->string('endpoint_hash', 64);
                $t->string('p256dh', 255)->nullable();
                $t->string('auth', 255)->nullable();
                $t->string('content_encoding', 20)->default('aesgcm');
                $t->dateTime('failed_at')->nullable();
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('push_subscriptions')) {
            $schema->create('push_subscriptions', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('staff_id')->nullable();
                $t->unsignedBigInteger('restaurant_id');
                $t->string('platform', 12)->default('web');
                $t->text('endpoint');
                $t->string('endpoint_hash', 64);
                $t->string('public_key', 255)->nullable();
                $t->string('auth_token', 255)->nullable();
                $t->string('content_encoding', 20)->default('aesgcm');
                $t->timestamps();
                $t->boolean('notify_new_order')->default(true);
                $t->dateTime('failed_at')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('device_name', 80)->nullable();
                $t->string('app_version', 24)->nullable();
                $t->string('os_version', 24)->nullable();
                $t->dateTime('last_seen_at')->nullable();
            });
        }
        if (! $schema->hasTable('storefront_tokens')) {
            $schema->create('storefront_tokens', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('post_id');
                $t->string('token', 64);
                $t->string('label', 80)->nullable();
                $t->longText('scopes')->nullable();
                $t->longText('allowed_origins')->nullable();
                $t->boolean('browser')->default(false);
                $t->dateTime('last_used_at')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! $schema->hasTable('post_views')) {
            $schema->create('post_views', static function (Blueprint $t): void {
                $t->increments('id');
                $t->integer('post_id')->nullable();
                $t->string('ip', 255)->nullable();
                $t->string('device', 255)->nullable();
                $t->string('lang', 10)->nullable();
                $t->string('source', 64)->nullable();
                $t->dateTime('date')->nullable();
                $t->integer('dwell_ms')->nullable();
                $t->string('exit_section', 60)->nullable();
            });
        }
        if (! $schema->hasTable('post_view_daily')) {
            $schema->create('post_view_daily', static function (Blueprint $t): void {
                $t->id();
                $t->integer('post_id');
                $t->date('date');
                $t->string('device', 255)->nullable();
                $t->string('lang', 10)->nullable();
                $t->integer('scans')->default(0);
            });
        }

        return $this;
    }

    /**
     * Later promotion tables: coupon terms (reseller, platform-plan and reseller-tier promotions) and the per-account
     * use counter (synthetic shapes for the importer tests).
     */
    public function laterPromotions(): self
    {
        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('reseller_coupon_terms')) {
            $schema->create('reseller_coupon_terms', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('coupon_id');
                $t->unsignedBigInteger('reseller_id')->nullable();
                $t->json('plan_ids')->nullable();
                $t->string('duration', 12)->default('once');
                $t->unsignedSmallInteger('duration_months')->nullable();
                $t->bigInteger('amount_off_minor')->nullable();
                $t->char('currency_code', 3)->nullable();
                $t->timestamps();
                $t->json('gateway_refs')->nullable();
                $t->string('target', 20)->default('reseller_plan');
                $t->json('intervals')->nullable();
                $t->timestamp('starts_at')->nullable();
                $t->boolean('auto_apply')->default(false);
                $t->boolean('new_accounts_only')->default(false);
                $t->unsignedInteger('per_account_limit')->nullable();
                $t->json('display')->nullable();
                $t->smallInteger('priority')->default(0);
            });
        }
        if (! $schema->hasTable('promotion_redemptions')) {
            $schema->create('promotion_redemptions', static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('coupon_id');
                $t->string('subject_type', 20);
                $t->unsignedBigInteger('subject_id');
                $t->unsignedInteger('seq');
                $t->string('reference', 64)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        return $this;
    }

    /** @param array<string, mixed> $attrs */
    public function roomType(int $id, int $storeId, array $attrs = []): int
    {
        $this->db->table('room_types')->insert($attrs + ['id' => $id, 'restaurant_id' => $storeId, 'name' => 'Room type '.$id]);

        return $id;
    }

    /** @param array<string, mixed> $attrs */
    public function staff(int $id, int $storeId, int $ownerId, array $attrs = []): int
    {
        $this->db->table('restaurant_staff')->insert($attrs + ['id' => $id, 'restaurant_id' => $storeId, 'owner_id' => $ownerId,
            'name' => 'Staff '.$id, 'email' => "staff{$id}@example.test", 'password' => '$2y$10$syntheticsyntheticsyntheticsyntheticsyntheticsyntheti']);

        return $id;
    }

    /**
     * Insert one synthetic row into a table created above (invented values only).
     *
     * @param  array<string, mixed>  $row  must contain its key ('id', or 'upgrade_id' for the legacy membership rows)
     */
    public function row(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) ($row['id'] ?? $row['upgrade_id']);
    }

    /** @param array<string, \Closure(Blueprint): mixed> $columns */
    private function addColumns(string $table, array $columns): void
    {
        $schema = $this->db->getSchemaBuilder();
        foreach ($columns as $name => $define) {
            if (! $schema->hasColumn($table, $name)) {
                $schema->table($table, static function (Blueprint $t) use ($define): void {
                    $define($t);
                });
            }
        }
    }

    /** A table outside the source prefix (another application's table in the same schema), raw name. */
    public function foreignTable(string $rawName): self
    {
        $this->db->statement('create table "'.str_replace('"', '', $rawName).'" (id integer primary key)');

        return $this;
    }
}
