<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Juwri\Core\Editions\EditionPackages;

/**
 * P1-04 slice 5 — `TABLES` is GENERATED from the Community migrations by `php artisan tenancy:tables`
 * (TenantTablesGenerator); do not edit it by hand. `PARENTS` and `USER_OWNED` are hand-maintained decisions the
 * generator keeps and checks against the schema. Every tenant-owned table: `store` = has store_id, `account` = has
 * account_id (J29 tenant key), `required` = store_id NOT NULL, `account_required` = account_id NOT NULL. Used by the
 * account fill (BelongsToAccount), the ownership re-key, the tenant-scope architecture test and the PostgreSQL RLS
 * policies. An installed edition package lists its own tables in its `tenant_tables` file (same generator); read the
 * merged lists through {@see tables()}, {@see parents()}, {@see userOwned()} and {@see get()}.
 * TenantTablesGeneratorTest fails when a committed list differs from the generator, TenantModelsTest when the
 * list and the live schema disagree.
 */
final class TenantTables
{
    /** @var array<string, array{store: bool, account: bool, required: bool, account_required: bool}> */
    public const TABLES = [
        'account_usage' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'accounting_accounts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'accounting_journal_lines' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'accounting_journals' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'accounting_period_locks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => false],
        'activity_logs' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'agent_conversations' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => false],
        'aggregator_connections' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'aggregator_sync_logs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'ai_menu_actions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'ai_theme_proposals' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'allergies' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'app_installations' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'audiences' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'billing_receipts' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'bio_blocks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'bio_clicks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_closed_days' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_holds' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_hours' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_lines' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_pending_refunds' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_services' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_slot_cells' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_staff' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'booking_staff_services' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'bookings' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'branch_hour_exceptions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'branch_menu' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'branch_stock_levels' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'branches' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'brands' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'call_logs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'campaign_recipients' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'campaigns' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'canned_replies' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'cart_lines' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'carts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'categories' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'catering_requests' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'check_payments' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'checklist_runs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'checklist_templates' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'combo_slot_options' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'combo_slots' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'combos' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'coupon_batches' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'coupon_redemptions' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'coupons' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'courier_deliveries' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'credentials' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'cuisine_store' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => false],
        'customer_addresses' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'customer_feedback' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'customer_notifications' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'customer_passkeys' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'customer_push_subscriptions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'customer_tokens' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'customers' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'daily_tokens' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'delivery_orders' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'delivery_slot_rules' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'disputes' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'drivers' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'entitlement_audit_logs' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'entitlement_overrides' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'expenses' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'external_invoices' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'feature_grants' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'fiscal_certificates' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'fiscal_devices' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'fiscal_documents' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'fitment_imports' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'folio_page_stats' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'food_safety_checks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'food_safety_logs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'gbp_reviews' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'gift_cards' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'hotel_booking_nights' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'hotel_bookings' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'hotel_event_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'hotel_events' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'hotel_extra_services' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'hotel_stays' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'housekeeping_tasks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'hungerstation_catalog_jobs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'hungerstation_event_logs' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'image_menus' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'import_batches' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'inapp_accounts' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'inapp_purchases' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'ingredients' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'item_kitchen_stations' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'item_modifier_group' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'kitchen_stations' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'laundry_slot_holds' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'leave_requests' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'leave_types' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'legal_acceptances' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'loyalty_accounts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'loyalty_ledger_entries' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'loyalty_programs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'marketplace_dispatch_offers' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'marketplace_reviews' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'meal_plans' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'media_assets' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'menu_banners' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'menu_item_events' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'menu_review_media' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'menu_reviews' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'menu_variants' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'message_costs' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'modifier_groups' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'modifiers' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'nps_responses' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'odoo_sync_records' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'order_adjustments' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'order_item_extras' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'order_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'order_refund_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'order_voice_notes' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'orders' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'paylink_onboardings' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'payroll_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'payroll_runs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pharmacy_licences' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'phone_lines' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_bridge_connections' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_card_reversal_exceptions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_item_mappings' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_offline_card_payments' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_offline_devices' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_offline_records' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_offline_snapshots' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_offline_zatca_invoices' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_offline_zatca_units' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_order_pushes' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'pos_shifts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'pos_terminals' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'price_schedules' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'print_jobs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'printer_profiles' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'purchase_order_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'purchase_orders' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'push_subscriptions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'qr_designs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'rate_plans' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'recipe_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'reservations' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'return_requests' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'room_rates' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'room_types' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'seller_withdrawals' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'service_requests' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'shift_notes' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'shift_swaps' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'shipments' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'shipping_methods' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'shipping_zones' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'shopper_customers' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'signage_screens' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'size_guides' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'skin_profiles' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'slot_holds' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'slot_seats' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'slug_redirects' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'sms_logs' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'sms_optouts' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'staff_availabilities' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'staff_documents' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_members' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_messages' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_onboarding_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'staff_passkeys' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_role_rates' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'staff_shifts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_synced_ops' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'staff_task_completions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_tasks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_time_clock_entries' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'staff_tokens' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'stock_alerts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'stock_count_items' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'stock_counts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'stock_movements' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_backup_schedules' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_backups' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_custom_blocks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_domains' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_follows' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_imports' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_page_view_daily' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => false],
        'store_page_views' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'store_payment_gateways' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_review_media' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'store_reviews' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'store_tables' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'store_transfers' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'storefront_api_idempotency' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'storefront_tokens' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'stores' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => true],
        'stories' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'supplier_invoices' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'supplier_payments' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'suppliers' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'switch_imports' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'table_checks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'table_merge_groups' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'table_merge_members' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'table_sessions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'team_channel_members' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'team_channels' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'team_message_reads' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'team_messages' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'template_rollout_stores' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'tenant_usage_daily' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'terminal_accounts' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'terminal_payments' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'theme_purchases' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'theme_setting_versions' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'theme_settings' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'transaction' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'translation_memory_entries' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'upgrades' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'vehicle_fitments' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'voice_caller_blocks' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'waiter_calls' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'waitlist_entries' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'wallet_transactions' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],
        'waste_logs' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'whatsapp_consents' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'whatsapp_conversations' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'whatsapp_inbound_messages' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'whatsapp_logs' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'whatsapp_number_healths' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'whatsapp_optouts' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'whatsapp_routes' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
        'whatsapp_templates' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'whatsapp_usage' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'whatsapp_windows' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],
        'wishlist_items' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],
    ];

    /**
     * Child tables whose store_id is copied from a parent row on create: [fk column, parent table], first match wins.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const PARENTS = [
        'accounting_journal_lines' => [['journal_id', 'accounting_journals']],
        'branch_menu' => [['menu_id', 'items'], ['branch_id', 'branches']],
        'branch_stock_levels' => [['branch_id', 'branches'], ['ingredient_id', 'ingredients']],
        'campaign_recipients' => [['campaign_id', 'campaigns']],
        'cart_lines' => [['cart_id', 'carts']],
        'check_payments' => [['table_check_id', 'table_checks']],
        'coupon_redemptions' => [['order_id', 'orders'], ['customer_id', 'customers']],
        'customer_addresses' => [['customer_id', 'customers']],
        'customer_tokens' => [['customer_id', 'customers']],
        'hotel_booking_nights' => [['booking_id', 'hotel_bookings']],
        'hotel_event_items' => [['event_id', 'hotel_events']],
        'item_kitchen_stations' => [['station_id', 'kitchen_stations'], ['menu_id', 'items']],
        'item_modifier_group' => [['menu_id', 'items'], ['modifier_group_id', 'modifier_groups']],
        'loyalty_ledger_entries' => [['loyalty_account_id', 'loyalty_accounts']],
        'marketplace_dispatch_offers' => [['order_id', 'orders']],
        'menu_review_media' => [['review_id', 'menu_reviews']],
        'menu_variants' => [['menu_id', 'items']],
        'modifier_groups' => [['menu_id', 'items']],
        'modifiers' => [['modifier_group_id', 'modifier_groups'], ['menu_id', 'items']],
        'order_adjustments' => [['order_id', 'orders']],
        'order_item_extras' => [['order_item_id', 'order_items']],
        'order_items' => [['order_id', 'orders']],
        'order_refund_items' => [['order_id', 'orders']],
        'payroll_items' => [['payroll_run_id', 'payroll_runs']],
        'purchase_order_items' => [['purchase_order_id', 'purchase_orders']],
        'recipe_items' => [['menu_id', 'items']],
        'shipments' => [['order_id', 'orders']],
        'staff_availabilities' => [['staff_id', 'staff_members']],
        'staff_onboarding_items' => [['staff_id', 'staff_members']],
        'staff_role_rates' => [['staff_id', 'staff_members']],
        'staff_tokens' => [['staff_id', 'staff_members']],
        'stock_count_items' => [['stock_count_id', 'stock_counts']],
        'store_review_media' => [['review_id', 'store_reviews']],
        'supplier_payments' => [['supplier_id', 'suppliers']],
        'table_merge_members' => [['group_id', 'table_merge_groups']],
        'team_channel_members' => [['channel_id', 'team_channels']],
        'team_message_reads' => [['message_id', 'team_messages']],
        'team_messages' => [['channel_id', 'team_channels']],
    ];

    /**
     * Store-less tables whose user_id is the owning account's user (account_id = that user's account).
     *
     * @var list<string>
     */
    public const USER_OWNED = [
        'account_usage',
        'app_installations',
        'billing_receipts',
        'coupon_batches',
        'coupons',
        'entitlement_audit_logs',
        'entitlement_overrides',
        'feature_grants',
        'inapp_accounts',
        'inapp_purchases',
        'legal_acceptances',
        'marketplace_reviews',
        'seller_withdrawals',
        'tenant_usage_daily',
        'theme_purchases',
        'transaction',
        'upgrades',
        'wallet_transactions',
    ];

    /** @var array{tables: array<string, array{store: bool, account: bool, required: bool, account_required: bool}>, parents: array<string, list<array{0: string, 1: string}>>, user_owned: list<string>}|null */
    private static ?array $merged = null;

    /**
     * Every tenant table: Community + installed edition packages, sorted by name.
     *
     * @return array<string, array{store: bool, account: bool, required: bool, account_required: bool}>
     */
    public static function tables(): array
    {
        return self::merged()['tables'];
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    public static function parents(): array
    {
        return self::merged()['parents'];
    }

    /** @return list<string> */
    public static function userOwned(): array
    {
        return self::merged()['user_owned'];
    }

    /** @return array{store: bool, account: bool, required: bool, account_required: bool}|null */
    public static function get(string $table): ?array
    {
        return self::merged()['tables'][$table] ?? null;
    }

    /**
     * Tables keyed by store_id that also carry account_id (re-keyed when a store changes account).
     *
     * @return list<string>
     */
    public static function storeAccountTables(): array
    {
        return array_keys(array_filter(self::tables(), fn ($t) => $t['store'] && $t['account']));
    }

    /** Test seam: forget the merged lists (EditionPackages::fake). */
    public static function flush(): void
    {
        self::$merged = null;
    }

    /** @return array{tables: array<string, array{store: bool, account: bool, required: bool, account_required: bool}>, parents: array<string, list<array{0: string, 1: string}>>, user_owned: list<string>} */
    private static function merged(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }
        $tables = self::TABLES;
        $parents = self::PARENTS;
        $userOwned = self::USER_OWNED;
        foreach (EditionPackages::tenantTables() as $package) {
            $tables += $package['tables'];
            $parents += $package['parents'];
            $userOwned = [...$userOwned, ...$package['user_owned']];
        }
        ksort($tables);
        ksort($parents);
        sort($userOwned);

        return self::$merged = ['tables' => $tables, 'parents' => $parents, 'user_owned' => array_values(array_unique($userOwned))];
    }
}
