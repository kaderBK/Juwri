<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\TenantTables;
use Tests\TestCase;

/**
 * P6-17 (ROADMAP-LATER #28): keep the schema distributable with Citus by account_id
 * (internal Citus readiness notes). It does not fix today's gaps; it stops NEW ones:
 *  - every TenantTables table carries account_id (else it cannot be a distributed table);
 *  - (PostgreSQL) no new unique index / primary key on an account_id table without account_id.
 * Existing exceptions are frozen in the allow-lists below; shrinking them is the migration plan.
 */
class CitusReadinessGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Tenant tables keyed by store only (no account_id column yet). Reason: pre-account_id rows; plan step 1. */
    private const NO_ACCOUNT_ID = [];

    /** Global/platform tables that reference a store but have no account_id (P6-17 audit). Reason: platform-side logs/listings; plan step 1. */
    private const STORE_ID_WITHOUT_ACCOUNT_ID = [];

    /**
     * Unique indexes (non-PK) on account_id tables that lack account_id, as "table(cols)".
     * Reason: globally unique business keys (tokens, slugs, uuids) — plan step 3 adds account_id or moves to reference tables.
     * Filled from the P6-17 PostgreSQL audit; a new entry here needs a reason in CITUS-READINESS.md.
     */
    private const UNIQUE_WITHOUT_ACCOUNT_ID = [
        'account_usage(user_id,metric,period)',
        'accounting_accounts(store_id,code)',
        'accounting_journals(store_id,source_type,source_id,rule_key)',
        'accounting_period_locks(store_id,period)',
        'aggregator_connections(provider,external_store_id)',
        'ai_theme_proposals(public_id)',
        'app_installations(app_id,user_id)',
        'billing_receipts(transaction_id)',
        'bio_clicks(block_id,day,source,device,country,ref)',
        'booking_holds(public_id)',
        'booking_pending_refunds(booking_id)',
        'booking_services(public_id)',
        'booking_slot_cells(staff_id,cell_at)',
        'booking_staff(public_id)',
        'booking_staff_services(staff_id,service_id)',
        'bookings(public_id)',
        'bookings(store_id,client_key)',
        'bookings(store_id,number)',
        'branch_hour_exceptions(branch_id,date)', // P6-291: same shape as branch_menu (branch rows are one account's)
        'branch_menu(branch_id,menu_id)',
        'branch_stock_levels(branch_id,ingredient_id)',
        'branches(public_id)',
        'branches(store_id,google_place_id)',
        'call_logs(call_sid)',
        'campaigns(id,store_id)',
        'categories(public_id)',
        'catering_requests(token)',
        'checklist_runs(checklist_template_id,branch_id,date)',
        'coupon_redemptions(coupon_id,customer_id,seq)',
        'coupons(issuer_key,code_key)', // P6-215: unique per issuer (owner/reseller/platform)
        'courier_deliveries(provider,external_delivery_id)',
        'credentials(store_id,feature,provider)',
        'credit_draws(user_id,usage_key)',
        'credit_lots(transaction_id)',
        'cuisine_store(cuisine_id,store_id)',
        'cuisine_suggestions(store_id,cuisine_id)',
        'customer_feedback(token)',
        'customer_passkeys(credential_id)',
        'customer_push_subscriptions(endpoint_hash)',
        'customer_tokens(token)',
        'customers(id,store_id)',
        'customers(public_id)',
        'customers(referral_code)',
        'customers(store_id,email)',
        'customers(store_id,oauth_provider,oauth_uid)',
        'customers(store_id,phone)',
        'customers(store_id)', // P6-98: unique (store_id, lower(email)) functional index, same key as customers(store_id,email)
        'daily_tokens(store_id,branch_id,token_date)',
        'delivery_orders(provider,external_id)',
        'directory_listings(slug)',
        'directory_listings(source,source_id)',
        'disputes(gateway,external_id)',
        'drivers(token)',
        'external_invoices(order_id,provider)',
        'feature_grants(user_id,feature_key)',
        'fiscal_devices(store_id,device_key)',
        'fiscal_documents(store_id,device_key,counter)',
        'fiscal_documents(store_id,source_ref)',
        'fiscal_documents(store_id,type,seq)',
        'fitment_imports(public_id)',
        'folio_page_stats(store_id,day,page)',
        'gbp_reviews(location_id,external_id)',
        'gift_cards(code)',
        'hotel_booking_nights(room_id,night)',
        'hotel_bookings(code)',
        'hotel_bookings(token)',
        'hotel_stays(token)',
        'import_batches(store_id,source,kind,file_hash)',
        'inapp_accounts(token)',
        'inapp_accounts(user_id)',
        'inapp_purchases(platform,original_key)',
        'item_kitchen_stations(branch_id,menu_id)',
        'item_modifier_group(menu_id,modifier_group_id,modifier_id)',
        'items(id,store_id)',
        'items(public_id)',
        'laundry_slot_holds(public_id)',
        'legal_acceptances(user_id,document,version)',
        'loyalty_accounts(customer_id,store_id)',
        'loyalty_accounts(id,store_id)',
        'loyalty_ledger_entries(order_id)',
        'loyalty_programs(store_id)',
        'marketplace_reviews(reviewable_type,reviewable_id,user_id)',
        'menu_reviews(id,store_id)',
        'menu_variants(public_id)',
        'modifier_groups(public_id)',
        'modifiers(public_id)',
        'nps_responses(order_id)',
        'odoo_sync_records(store_id,kind,source_id)',
        'order_adjustments(order_id,idempotency_key)',
        'order_items(id,store_id)',
        'order_voice_notes(token)',
        'orders(id,store_id)',
        'orders(public_id)',
        'orders(store_id,client_uuid)',
        'orders(track_token)',
        'phone_lines(provider,number)',
        'pos_bridge_connections(provider,store_id)',
        'pos_card_reversal_exceptions(store_id,client_uuid)',
        'pos_item_mappings(provider,menu_id)',
        'pos_offline_card_payments(provider,provider_reference)',
        'pos_offline_card_payments(store_id,client_key)',
        'pos_offline_devices(store_id,device_id)',
        'pos_offline_devices(token_hash)',
        'pos_offline_records(store_id,client_key)',
        'pos_offline_snapshots(store_id,branch_id,version)',
        'pos_offline_zatca_invoices(unit_id,invoice_hash)',
        'pos_order_pushes(order_id,provider)',
        'print_jobs(job_key)',
        'push_subscriptions(endpoint_hash)',
        'recipe_items(menu_id,ingredient_id)',
        'reseller_customer_subscriptions(reseller_id,user_id)',
        'reseller_team_members(invite_token)',
        'reseller_team_members(reseller_id,user_id)',
        'reservations(public_id)',
        'reservations(rwg_booking_id)',
        'reservations(token)',
        'return_requests(public_id)',
        'room_rates(room_type_id,rate_plan_id,date)',
        'shipping_methods(public_id)',
        'shipping_zones(public_id)',
        'shipping_zones(store_id,code)',
        'shopper_customers(shopper_id,store_id)',
        'shopper_favorites(shopper_id,store_id)',
        'shopper_follows(shopper_id,store_id)',
        'signage_screens(token)',
        'size_guides(public_id)',
        'slot_holds(public_id)',
        'slot_seats(store_id,branch_key,slot_at,seat_no)',
        'slug_redirects(old_slug)',
        'sms_optouts(phone,store_id)',
        'staff_availabilities(staff_id,weekday,preference)',
        'staff_members(email)',
        'staff_members()', // P6-98: unique lower(email) functional index (attname list is empty for an expression)
        'staff_passkeys(credential_id)',
        'staff_role_rates(staff_id,role)',
        'staff_synced_ops(op_id,store_id)', // P6-206: offline dedupe key per store (was op_id alone)
        'staff_task_completions(staff_task_id,task_date)',
        'staff_tokens(token)',
        'stock_alerts(public_id)',
        'stock_alerts(store_id,contact_hash,target)',
        'stock_count_items(stock_count_id,ingredient_id)',
        'store_backups(token)',
        'store_domains(host)',
        'store_follows(customer_id,store_id)',
        'store_imports(token)',
        'store_order_counts(store_id,period)',
        'store_page_view_daily(store_id,date,device,lang)',
        'store_payment_gateways(store_id,gateway)',
        'store_review_media(review_id,media_asset_id)',
        'store_tables(key)',
        'store_transfers(token)',
        'storefront_api_idempotency(store_id,operation,idempotency_key)',
        'storefront_tokens(token)',
        'stores(google_place_id)',
        'stores(public_id)',
        'table_merge_members(group_id,table_id)',
        'table_sessions(token)',
        'team_channel_members(channel_id,staff_id)',
        'team_message_reads(message_id,staff_id)',
        'template_rollout_stores(rollout_id,store_id)',
        'tenant_usage_daily(date,user_id,metric)',
        'terminal_accounts(store_id,provider)',
        'terminal_payments(store_id,client_uuid)',
        'theme_purchases(user_id,theme_id)',
        'theme_settings(store_id,template)',
        'transaction(invoice_number)',
        'transaction(payment_id,transaction_gatway)',
        'transaction(public_id)',
        'translation_memory_entries(store_id,lang,source_hash)',
        'users(email)',
        'users()', // P6-98: unique lower(email) and lower(username) functional indexes, same keys as users(email)/users(username)
        'users(public_id)',
        'users(username)',
        'voice_caller_blocks(store_id,number)',
        'waitlist_entries(rwg_entry_id)',
        'wallet_transactions(ref)',
        'whatsapp_consents(store_id,phone)',
        'whatsapp_conversations(store_id,phone)',
        'whatsapp_number_healths(provider,sender_id)',
        'whatsapp_optouts(phone,store_id)',
        'whatsapp_routes(provider,sender_id)',
        'whatsapp_templates(store_id,provider,name,language)',
        'whatsapp_windows(store_id,phone)',
        'wishlist_items(store_id,customer_id,menu_id)',
        'wishlist_items(store_id,guest_key,menu_id)',
    ];

    public function test_every_registered_tenant_table_is_account_keyed(): void
    {
        $missing = array_keys(array_filter(TenantTables::tables(), fn ($t) => ! $t['account']));
        $this->assertSame([], array_values(array_diff($missing, self::NO_ACCOUNT_ID)),
            'New tenant table without account_id: Citus cannot distribute it. Add account_id (see CITUS-READINESS.md).');
    }

    public function test_no_new_store_scoped_table_without_account_id(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (tools/p104/rls_pg.sh on the runner).');
        }
        $rows = DB::select(<<<'SQL'
            select c.table_name as t from information_schema.columns c
            where c.table_schema = current_schema() and c.column_name = 'store_id'
              and not exists (select 1 from information_schema.columns a where a.table_schema = c.table_schema
                  and a.table_name = c.table_name and a.column_name = 'account_id')
            order by 1
            SQL);
        $bad = array_map(fn ($r) => $r->t, $rows);
        $this->assertSame([], array_values(array_diff($bad, self::STORE_ID_WITHOUT_ACCOUNT_ID)),
            'New store-scoped table without account_id: it cannot be co-located with its store. Add account_id + TenantTables entry.');
    }

    public function test_no_new_unique_index_without_the_distribution_column(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (tools/p104/rls_pg.sh on the runner).');
        }
        $rows = DB::select(<<<'SQL'
            select t.relname as tbl, ix.indisprimary as pk,
                   array_to_string(array(select a.attname from unnest(ix.indkey) k
                       join pg_attribute a on a.attrelid = t.oid and a.attnum = k), ',') as cols
            from pg_index ix
            join pg_class t on t.oid = ix.indrelid
            join pg_namespace n on n.oid = t.relnamespace
            where n.nspname = current_schema() and ix.indisunique and not ix.indisprimary
              and exists (select 1 from pg_attribute a where a.attrelid = t.oid and a.attname = 'account_id' and not a.attisdropped)
            SQL);
        $bad = [];
        foreach ($rows as $r) {
            if (! in_array('account_id', explode(',', $r->cols), true)) {
                $bad[] = "{$r->tbl}({$r->cols})";
            }
        }
        sort($bad);
        $this->assertSame([], array_values(array_diff($bad, self::UNIQUE_WITHOUT_ACCOUNT_ID)),
            'New unique index without account_id: Citus requires the distribution column in every unique constraint.');
    }
}
