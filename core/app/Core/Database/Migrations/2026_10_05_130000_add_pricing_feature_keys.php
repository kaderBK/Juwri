<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing build (owner 2026-10-05, PRICING.md §3): the plan keys the feature matrix needed that the catalogue did not
 * have. Plan keys (group `plan`, read through Entitlements / plan_feature / effective_limit), not kill-switches: no
 * default value, not killable. A `count` key absent from a plan stays unlimited, so plans written before this key
 * existed keep today's behaviour. Inserts only the keys that are missing; never touches an existing row.
 */
return new class extends Migration
{
    /**
     * Upsell copy is catalogue data written verbatim (never through ___()), like FeaturePlatformCatalogSeeder's.
     * The relay's add-on SKU is wired by the PricingCatalogueSeeder when it creates the add-on (no dangling SKU).
     *
     * @var array<string, array{kind: string, label: string, description: string, sellable_via: list<string>, upsell: array{0: string, 1: string}}>
     */
    private const KEYS = [
        'order_limit' => ['kind' => 'count', 'label' => 'Orders per month (soft cap)', 'description' => 'Orders per store per calendar month before the plan asks the owner to upgrade. Orders above it are still accepted (999 = unlimited).', 'sellable_via' => ['plan'], 'upsell' => ['Take every order, every month', 'Unlimited monthly orders, with no reminder to upgrade when a busy month comes.']],
        'branch_limit' => ['kind' => 'count', 'label' => 'Branch Limit', 'description' => 'Max branches per store (999 = unlimited).', 'sellable_via' => ['plan'], 'upsell' => ['Open another branch', 'Run more branches under one store, each with its own hours, QR codes and menu prices.']],
        'staff_limit' => ['kind' => 'count', 'label' => 'Staff Limit', 'description' => 'Max staff accounts per store (999 = unlimited).', 'sellable_via' => ['plan'], 'upsell' => ['Bring the whole team', 'Add more staff accounts, each with their own login and permissions.']],
        'pos_terminal_limit' => ['kind' => 'count', 'label' => 'POS Devices', 'description' => 'Named POS devices (registers / tills) per store (999 = unlimited).', 'sellable_via' => ['plan'], 'upsell' => ['Add another till', 'Run more POS devices in the same store, all on the same orders and stock.']],
        'delivery_relay_limit' => ['kind' => 'count', 'label' => 'Delivery-App Relay Connections', 'description' => 'Delivery apps connected through the juwri Cloud relay (bought per app as an add-on).', 'sellable_via' => ['plan', 'addon'], 'upsell' => ['Connect another delivery app', 'Bring orders from one more delivery app into your normal order queue through the juwri Cloud relay.']],
        'delivery' => ['kind' => 'boolean', 'label' => 'Delivery & Delivery Zones', 'description' => 'Take delivery orders with delivery zones and charges.', 'sellable_via' => ['plan'], 'upsell' => ['Deliver to your customers', 'Take delivery orders with your own zones, minimum order values and delivery charges.']],
        'coupons' => ['kind' => 'boolean', 'label' => 'Coupons', 'description' => 'Discount coupons at checkout.', 'sellable_via' => ['plan'], 'upsell' => ['Run discount codes', 'Create coupon codes customers apply at checkout, with usage limits and expiry dates.']],
        'whatsapp_order_alerts' => ['kind' => 'boolean', 'label' => 'WhatsApp Order Alerts', 'description' => 'Order confirmations and status updates to customers on WhatsApp.', 'sellable_via' => ['plan'], 'upsell' => ['Tell customers on WhatsApp', 'Send order confirmations and status updates to your customers on WhatsApp.']],
        'branch_reports' => ['kind' => 'boolean', 'label' => 'Multi-Branch Reports', 'description' => 'The cross-branch report page comparing every branch of a store.', 'sellable_via' => ['plan'], 'upsell' => ['Compare every branch', 'One report that puts all your branches side by side.']],
        'odoo_connector' => ['kind' => 'boolean', 'label' => 'Odoo Connector', 'description' => 'Send orders, payments and stock moves to Odoo.', 'sellable_via' => ['plan'], 'upsell' => ['Keep Odoo in sync', 'Send orders, payments and stock moves to your Odoo automatically.']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }
        $position = (int) DB::table('features')->max('position');
        $now = now();
        foreach (self::KEYS as $key => $def) {
            if (DB::table('features')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('features')->insert([
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'group_' => 'plan',
                'kind' => $def['kind'],
                'default_value' => null,
                'sellable_via' => json_encode($def['sellable_via']),
                'addon_sku' => null,
                'platform_only' => false,
                'plan_gated' => false,
                'killable' => false,
                'shipped' => true,
                'tier' => 'core',
                'upsell_title' => $def['upsell'][0],
                'upsell_body' => $def['upsell'][1],
                'position' => ++$position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('features')) {
            DB::table('features')->whereIn('key', array_keys(self::KEYS))->delete();
        }
    }
};
