<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Support\Facades\Hash;

/**
 * The explicit source → target maps (plan §1.2 core tables, §1.3 later-lineage tables). Source names are the old
 * platform's (they live only in this module, J52b). Column lists follow the base schema plus the later columns whose
 * shape matches the target; every other source column is reported as unknown (R1), never copied by name.
 *
 * Money columns go through LegacyMoney only. Order lines and their extras get their price from
 * {@see HistoryImporter} (the source-recorded price, else a marked snapshot of the source menu at migration time);
 * order totals are completed after the lines (plan §4). Billing history converts each row in its own currency.
 */
final class LegacyTableMaps
{
    public const USERS = 'user';

    public const STORES = 'posts';

    public const STORE_OPTIONS = 'post_options';

    public const PLATFORM_OPTIONS = 'options';

    public const SECRETS = 'credentials';

    /** Source store domains (later lineage, plan §3). */
    public const DOMAINS = 'restaurant_domains';

    /** Source resellers: not imported here (Reseller edition); only their hosts are listed and protected. */
    public const RESELLERS = 'resellers';

    /** Source column of the store owner on the store table. */
    public const STORE_OWNER = 'user_id';

    /** Source column naming the store on store-scoped tables (first present wins). */
    public const STORE_KEYS = ['restaurant_id', 'post_id'];

    /** Platform option holding the platform currency (fallback when a store has none). */
    public const PLATFORM_CURRENCY_OPTION = 'currency_code';

    /** Store option holding the store currency. */
    public const STORE_CURRENCY_OPTION = 'currency_code';

    /**
     * `transaction_method` of a source order payment: its `user_id` holds the STORE id (and `product_id` the order id),
     * not a user. Such rows (and their receipts) are imported with their store, never as a user's billing.
     */
    public const ORDER_PAYMENT = 'order';

    /** Source tables read by the importer outside a TableMap (not "unmapped"). */
    public const HANDLED_ELSEWHERE = ['post_options', 'options', 'credentials', PlanMemberships::SUBSCRIPTIONS, PlanMemberships::UPGRADES, PlanMemberships::ADDONS];

    /**
     * Source tables never migrated (plan §1.3 "Not migrated", DDL diff §3 "S"): queues, sessions and tokens (users and
     * customers sign in again, Q8), transient table/service state, history and logs, caches. Listed so the unmapped
     * report shows only real gaps. Exact names here; families in {@see NEVER_PATTERNS}.
     */
    public const NEVER = [
        'migrations', 'jobs', 'failed_jobs', 'job_batches', 'sessions', 'cache', 'cache_locks', 'password_resets',
        'password_reset_tokens', 'personal_access_tokens', 'customer_tokens', 'oauth_access_tokens', 'oauth_auth_codes',
        'oauth_clients', 'oauth_personal_access_clients', 'oauth_refresh_tokens', 'api_tokens', 'staff_tokens',
        'email_change_undos', 'waiter_call', 'service_requests', 'table_checks', 'table_sessions', 'daily_tokens',
        'waitlist_entries', 'housekeeping_tasks', 'gbp_reviews', 'customer_notifications', 'theme_setting_versions',
        'site_page_revisions', 'activity_logs', 'api_request_logs', 'abuse_events', 'login_audits', 'bio_clicks',
        'call_logs', 'docs_events', 'owner_screen_views', 'tenant_usage_daily', 'webhook_deliveries', 'menu_item_events',
        'entitlement_audit', 'sms_log', 'translation_memory', 'import_batches', 'store_imports', 'store_backups',
        'store_backup_schedules', 'store_transfers', 'peer_benchmarks',
    ];

    /** Families of never-migrated source tables (logs, stats, experiments, AI/agent history, sync logs, chat logs). */
    public const NEVER_PATTERNS = [
        '/^table_merge_/', '/_stats$/', '/^ab_/', '/^ai_/', '/^agent_/', '/_sync_logs$/', '/^hs_/', '/^platform_/',
        '/^whatsapp_(log|events|inbound|usage|windows|conversations|number_health)/',
    ];

    /**
     * Back-office data skipped at launch by owner decision J58c (owners set it up again; can be added per feature):
     * inventory / recipes / suppliers, staff shifts / HR / payroll, fiscal devices and accounting, POS / printer /
     * delivery / integration settings. Listed in the report under `skipped_by_decision`, never as unmapped.
     */
    public const SKIPPED_BY_DECISION = [
        '/^(ingredients|recipe_items|waste_logs|suppliers|expenses|check_payments|ledger_accounts|ledger_entries)$/' => 'J58c back-office (inventory, recipes, suppliers, accounting): set up again by the owner',
        '/^(stock_|supplier_|purchase_order)/' => 'J58c back-office (inventory, purchasing): set up again by the owner',
        '/^(staff_shifts|staff_time_clock|staff_(?!tokens$)|shift_|leave_|payroll_)/' => 'J58c back-office (staff shifts, HR, payroll)',
        '/^(fiscal_|gl_)/' => 'J58c back-office (fiscal devices and documents, general ledger): fiscal history starts at cut-over',
        '/^(pos_|printer_profiles$|print_jobs$|signage_screens$|drivers$|courier_deliveries$|delivery_orders$|shipments$)/' => 'J58c back-office (POS, printers, delivery): reconnected by the owner',
        '/^(aggregator_connections|pos_bridge_connections|webhook_endpoints|apps|app_installations|paylink_onboardings)$/' => 'J58c integrations (settings with secrets): reconnected by the owner',
        '/^(marketplace_media|marketplace_reviews)$/' => 'J58c integrations: media and reviews of app-store listings (the apps are not imported)',
        '/^mk_dispatch_offers$/' => 'J58c back-office (delivery): live driver dispatch offers',
        '/^(support_tickets|support_messages|canned_replies|disputes)$/' => 'J59g not migrated by owner decision: support tickets and disputes',
        '/^(plans|plan_prices|plan_options|plan_entitlements|subscription_events)$/' => 'J59c plans are this install\'s own (source plans mapped by LEGACY_IMPORT_PLAN_MAP); the current membership moves, not its event history',
    ];

    /** Why $table is skipped by an owner decision, or null. */
    public static function skippedByDecision(string $table): ?string
    {
        foreach (self::SKIPPED_BY_DECISION as $pattern => $why) {
            if (preg_match($pattern, $table) === 1) {
                return $why;
            }
        }

        return null;
    }

    /** Is $table one the import never migrates (no report line)? */
    public static function never(string $table): bool
    {
        if (in_array($table, self::NEVER, true)) {
            return true;
        }
        foreach (self::NEVER_PATTERNS as $pattern) {
            if (preg_match($pattern, $table) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Mark of a user / staff member whose source two-factor setup was not carried over (J58b): set it up again. */
    public const TWO_FACTOR_REENROL = 'two_factor_reenrol';

    /** Mark of a user whose source reseller is not (yet) on this install: the link is kept here for the reseller import. */
    public const RESELLER_PENDING = 'reseller_pending';

    /**
     * Users. Two-factor secrets are NOT carried over (J58b: they are sealed with the source key, and a re-enrolment is
     * safer than moving them): two-factor is off here and a user who had it confirmed is marked to set it up again at
     * first login. A reseller link is kept only when that reseller is already on this install (else marked).
     */
    public static function users(): TableMap
    {
        return new TableMap(
            source: self::USERS,
            target: 'users',
            scope: TableMap::PLATFORM,
            columns: [
                'user_type' => 'user_type', 'group_id' => 'group_id', 'username' => 'username', 'email' => 'email',
                'name' => 'name', 'password' => 'password', 'image' => 'image', 'country' => 'country',
                'oauth_provider' => 'oauth_provider', 'oauth_uid' => 'oauth_uid', 'preferred_locale' => 'preferred_locale',
            ],
            dates: ['email_verified_at' => 'email_verified_at', 'lastactive' => 'lastactive', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['status' => 'status', 'abuse_hold' => 'abuse_hold'],
            money: ['balance_minor' => 'balance'],
            // Sessions are not migrated (plan Q8): remember-me tokens are dropped. The landing-page preference has no
            // equivalent here.
            ignore: ['remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'login_landing'],
            uses: ['country_code', 'reseller_id'],
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                // ISO alpha-2 only (the source column was char(50)); anything else is reported, not truncated.
                $code = strtoupper(trim((string) ($row['country_code'] ?? '')));
                if ($code !== '' && preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
                    $ctx->report->conflict('users.country_code: not an ISO alpha-2 code, set NULL', 'user#'.$out['id']);
                    $code = '';
                }
                $out['country_code'] = $code === '' ? null : $code;
                $out['account_id'] = $ctx->accounts[(int) $out['id']] ?? null;
                if (($out['oauth_provider'] ?? null) === '') {
                    $out['oauth_provider'] = null;
                }
                $id = (int) $out['id'];
                if (trim((string) ($row['two_factor_secret'] ?? '')) !== '' || ($row['two_factor_confirmed_at'] ?? null) !== null) {
                    $ctx->mark('users', $id, self::TWO_FACTOR_REENROL);
                    $ctx->report->count('users_two_factor_reenrol');
                }
                $out['reseller_id'] = self::reseller($row['reseller_id'] ?? null, 'users', $id, $ctx);
                // J59c: the plan comes with the owner's membership ({@see PlanMemberships}); until then free (or trial).
                $out['group_id'] = PlanMemberships::startGroup($row['group_id'] ?? null);

                return $out;
            },
        );
    }

    /**
     * A source reseller id kept only when that same source reseller was imported onto this install with its id (the
     * Reseller edition's import records it in the side table); otherwise NULL, marked with the id so the reseller import
     * can link it, and reported.
     */
    public static function reseller(mixed $value, string $table, int $id, ImportContext $ctx): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }
        $reseller = (int) $value;
        // Only the SAME source reseller, imported with its id (side-table record): a reseller created on this install
        // that happens to have this id is another tenant (J29).
        $imported = $ctx->target->table(RowLoader::SIDE_TABLE)->where('source_table', self::RESELLERS)
            ->where('source_id', (string) $reseller)->where('target_table', 'resellers')->where('target_id', (string) $reseller)->exists();
        if ($imported && $ctx->target->table('resellers')->where('id', $reseller)->exists()) {
            return $reseller;
        }
        $ctx->mark($table, $id, self::RESELLER_PENDING, ['reseller_id' => $reseller]);
        $ctx->report->conflict($table.'.reseller_id: reseller not on this install (link kept as a mark for the reseller import), set NULL', $table.'#'.$id);

        return null;
    }

    /**
     * Platform allergies: source rows with no store. Rows of a store are that store's (J29) and come with it
     * ({@see storeAllergies()}); the platform phase skips them.
     */
    public static function allergies(): TableMap
    {
        return new TableMap(
            source: 'allergies',
            target: 'allergies',
            scope: TableMap::PLATFORM,
            storeColumns: self::STORE_KEYS,
            columns: ['title' => 'title', 'translations' => 'translations', 'image' => 'image', 'position' => 'position', 'active' => 'active'],
        );
    }

    /** Allergies a store defined for itself (ids kept: item allergy lists name them). */
    public static function storeAllergies(): TableMap
    {
        return new TableMap(
            source: 'allergies',
            target: 'allergies',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: ['title' => 'title', 'translations' => 'translations', 'image' => 'image', 'position' => 'position', 'active' => 'active'],
        );
    }

    public static function stores(): TableMap
    {
        $same = [
            'slug', 'color', 'title', 'sub_title', 'description', 'main_image', 'cover_image', 'timing', 'address', 'phone',
            'website', 'bio_title', 'never_show_title', 'bio_description', 'bio_main_image', 'bio_cover_image',
            'head_code', 'facebook_pixel', 'google_tag', 'css_code', 'js_code', 'footer_code', 'bio_builder',
            'bio_design_json', 'bio_html', 'bio_published_html',
        ];
        for ($i = 1; $i <= 10; $i++) {
            $same[] = 'link_label'.$i;
            $same[] = 'link'.$i;
        }
        for ($i = 1; $i <= 12; $i++) {
            $same[] = 'bio_color_'.$i;
        }

        return new TableMap(
            source: self::STORES,
            target: 'stores',
            scope: TableMap::STORE,
            columns: array_combine($same, $same) + ['user_id' => self::STORE_OWNER],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['custom_code_enabled' => 'custom_code_enabled', 'is_verified' => 'is_verified', 'catalog_visible' => 'catalog_visible'],
            uses: ['store_type'],
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                // Slugs are kept byte-for-byte (rule 4); only the row's own id names the store.
                unset($out['store_id']);
                // The store's vertical (hotel, retail, …) when this install knows it; else the default, reported.
                $type = strtolower(trim((string) ($row['store_type'] ?? '')));
                if ($type !== '') {
                    if (array_key_exists($type, (array) config('verticals.types', []))) {
                        $out['store_type'] = $type;
                    } else {
                        $ctx->report->conflict('stores.store_type: type unknown to this install, default kept', 'store#'.(int) $out['id'].' '.$type);
                    }
                }
                if (! in_array($out['bio_builder'] ?? null, ['blocks', 'html'], true)) {
                    unset($out['bio_builder']);
                }

                return $out;
            },
        );
    }

    public static function branches(): TableMap
    {
        return new TableMap(
            source: 'branches',
            target: 'branches',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'name' => 'name', 'address' => 'address', 'phone' => 'phone', 'whatsapp' => 'whatsapp', 'hours' => 'hours',
                'pos_settings' => 'pos_settings', 'timezone' => 'timezone', 'latitude' => ['latitude', 'lat'],
                'longitude' => ['longitude', 'lng'], 'order_types' => 'order_types', 'closed_message' => 'closed_message',
                'position' => 'position',
            ],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['active' => 'active', 'accepting_orders' => 'accepting_orders'],
            transform: static function (array $out): array {
                $out['name'] = (string) ($out['name'] ?? '');

                return $out;
            },
        );
    }

    public static function storeTables(): TableMap
    {
        return new TableMap(
            source: 'restaurant_tables',
            target: 'store_tables',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'branch_id' => 'branch_id', 'table_no' => 'table_no', 'key' => 'key', 'area_type' => 'area_type',
                'label' => 'label', 'map_x' => 'map_x', 'map_y' => 'map_y', 'shape' => 'shape', 'seats' => 'seats',
                'min_covers' => 'min_covers', 'max_covers' => 'max_covers', 'floor_label' => 'floor_label',
                'section' => 'section', 'note' => 'note', 'room_type_id' => 'room_type_id', 'server_staff_id' => 'server_staff_id',
                'hk_status' => 'hk_status', 'oo_status' => 'oo_status', 'oo_until' => 'oo_until',
            ],
            // Room types and staff are loaded before the tables; one not imported for this store is dropped (reported).
            refs: ['branch_id' => 'branches', 'room_type_id' => 'room_types', 'server_staff_id' => 'staff_members'],
            // Plan §1.2: status starts free; the check code is generated fresh by this install (it is not in any
            // printed URL). Table keys are copied unchanged (rule 5).
            defaults: ['status' => 'free'],
            ignore: ['status', 'status_changed_at', 'verify_code', 'verify_code_rotated_at', 'party_size'],
        );
    }

    public static function categories(): TableMap
    {
        return new TableMap(
            source: 'menu_categories',
            target: 'categories',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'name' => 'name', 'image' => 'image', 'parent' => 'parent', 'position' => 'position',
                'translations' => 'translations', 'active' => 'active', 'available_from' => 'available_from',
                'available_to' => 'available_to', 'available_days' => 'available_days',
                'available_order_types' => 'available_order_types',
            ],
            selfParent: 'parent',
        );
    }

    /**
     * Catalogue items. A soft-deleted source item keeps its `deleted_at` (never live here) and is still imported, so
     * the order lines that name it resolve. Aggregator sync columns and the recipe auto-86 flag are not imported
     * (integrations and recipes are back-office data, J58c).
     */
    public static function items(): TableMap
    {
        $same = [
            'category_id', 'name', 'description', 'image', 'type', 'allergies', 'active', 'position', 'translations',
            'available_from', 'available_to', 'available_days', 'available_order_types', 'brand_id', 'station_id',
            'course', 'prep_minutes', 'plu', 'pricing_unit', 'turnaround_hours', 'weight', 'dimensions', 'attributes',
            'external_sku', 'stock_qty', 'low_stock_threshold', 'gallery_images', 'batch_no', 'expiry_date', 'spice_level',
            'is_daily_special', 'calories', 'protein', 'carbs', 'fat', 'rating_avg', 'rating_count', 'dietary_tags',
        ];
        $flags = ['is_sold_out', 'track_inventory', 'age_restricted', 'catering_only', 'is_vegan', 'is_halal', 'is_gluten_free', 'is_popular'];

        return new TableMap(
            source: 'menu',
            target: 'items',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: array_combine($same, $same),
            dates: ['deleted_at' => 'deleted_at'],
            bools: array_combine($flags, $flags),
            money: ['price_minor' => 'price', 'cost_minor' => 'cost'],
            // Brands and kitchen stations are not imported by this loader set: a set id becomes NULL and is reported.
            refs: ['category_id' => 'categories', 'brand_id' => 'brands', 'station_id' => 'kitchen_stations'],
            currency: true,
            ignore: ['auto_86_recipe', 'hs_sku', 'hs_product_id', 'hs_synced_at', 'hs_sync_status'],
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                // The allergy list names allergy ids: keep the platform's and this store's only (J29).
                $list = $out['allergies'] ?? null;
                if (is_string($list) && trim($list) !== '') {
                    $ids = array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $v): bool => $v !== ''));
                    $kept = array_values(array_filter($ids, static fn (string $v): bool => ctype_digit($v) && $ctx->resolves('allergies', (int) $v)));
                    if (count($kept) !== count($ids)) {
                        $ctx->report->conflict('items.allergies: allergy ids not of this store or the platform dropped', 'menu#'.(int) $out['id']);
                    }
                    $out['allergies'] = $kept === [] ? null : implode(',', $kept);
                }

                return $out;
            },
        );
    }

    public static function customers(): TableMap
    {
        return new TableMap(
            source: 'customers',
            target: 'customers',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'name' => 'name', 'email' => 'email', 'phone' => 'phone', 'password' => 'password',
                'oauth_provider' => 'oauth_provider', 'oauth_uid' => 'oauth_uid', 'avatar_url' => 'avatar_url',
                'birthday' => 'birthday', 'source' => 'source', 'tags' => 'tags', 'notes' => 'notes',
                'orders_count' => 'orders_count', 'preferences' => 'preferences', 'allergies' => 'allergies',
                'system_tags' => 'system_tags', 'referral_code' => 'referral_code', 'referred_by_code' => 'referred_by_code',
                'last_birthday_offer_sent_year' => 'last_birthday_offer_sent_year',
            ],
            dates: [
                'email_verified_at' => 'email_verified_at', 'phone_verified_at' => 'phone_verified_at',
                'consent_at' => 'consent_at', 'last_order_at' => 'last_order_at', 'created_at' => 'created_at',
                'updated_at' => 'updated_at', 'last_winback_at' => 'last_winback_at',
            ],
            bools: ['marketing_consent' => 'marketing_consent', 'sms_consent' => 'sms_consent', 'notifications_enabled' => 'notifications_enabled'],
            money: ['total_spent_minor' => 'total_spent'],
            currency: true,
            // Customer sessions / remember-me are not migrated (plan Q8). A pending (unconfirmed) password change is
            // dropped: the customer keeps the confirmed one.
            ignore: ['remember_token', 'pending_password'],
            transform: static function (array $out): array {
                $out['name'] = (string) ($out['name'] ?? '');

                return $out;
            },
        );
    }

    /**
     * Money columns of an order: target *_minor => source amount. Converted with the store currency's exponent, or the
     * order's own currency's when the order names another one (J58a, {@see HistoryImporter::order()}).
     */
    public const ORDER_MONEY = [
        'discount_amount_minor' => 'discount_amount', 'tip_amount_minor' => 'tip_amount',
        'wallet_applied_minor' => 'wallet_applied', 'gift_card_applied_minor' => 'gift_card_applied',
        'mk_wallet_applied_minor' => 'mk_wallet_applied', 'channel_fee_minor' => 'channel_fee',
        'amount_tendered_minor' => 'amount_tendered', 'change_due_minor' => 'change_due',
        'service_charge_amount_minor' => 'service_charge_amount', 'donation_amount_minor' => 'donation_amount',
        // Later source versions recorded the totals: those win over anything derived (plan §4, Q3).
        'subtotal_minor' => 'subtotal', 'tax_total_minor' => 'tax_total', 'grand_total_minor' => 'grand_total',
    ];

    /**
     * Orders. The customer tracking token is kept verbatim (it is in links customers hold); the gift card and coupon
     * of an order are kept when they are rows of the same store (gift card balances derive from them). Aggregator,
     * POS-shift and driver ids name back-office rows that are not imported (J58c) and are dropped.
     */
    public static function orders(): TableMap
    {
        $same = [
            'type', 'customer_name', 'table_number', 'phone_number', 'address', 'status', 'message', 'payment_gateway',
            'customer_id', 'branch_id', 'client_uuid', 'payment_method', 'delivery_lat', 'delivery_lng', 'covers',
            'service_location', 'fx_rate_base', 'locale', 'coupon_id', 'gift_card_id', 'track_token', 'source',
            'tax_breakdown', 'token_number', 'token_date', 'vehicle_details', 'delivery_status', 'refund_request_status',
            'refund_request_reason', 'refund_method', 'cart_recovery_channel', 'driver_note', 'merged_from_table_id',
            'created_by_staff_id', 'served_by_staff_id', 'pod_photo',
        ];
        $dates = [
            'created_at', 'updated_at', 'scheduled_at', 'completed_at', 'cancelled_at', 'curbside_arrived_at', 'fire_at',
            'released_at', 'preparing_at', 'ready_at', 'packing_at', 'shipped_at', 'collected_at', 'refund_requested_at',
            'printed_at', 'wa_cart_recovery_at', 'assigned_at', 'picked_up_at', 'out_for_delivery_at', 'delivered_at',
            'held_at', 'stock_restored_at', 'pod_verified_at',
        ];
        $flags = ['seen', 'is_paid', 'is_curbside', 'is_rush', 'is_catering'];

        return new TableMap(
            source: 'orders',
            target: 'orders',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: array_combine($same, $same),
            dates: array_combine($dates, $dates),
            bools: array_combine($flags, $flags),
            money: self::ORDER_MONEY,
            refs: [
                'customer_id' => 'customers', 'branch_id' => 'branches', 'gift_card_id' => 'gift_cards',
                'coupon_id' => 'coupons', 'merged_from_table_id' => 'store_tables',
                'created_by_staff_id' => 'staff_members', 'served_by_staff_id' => 'staff_members',
            ],
            currency: true,
            // A delivery OTP is a one-time code of a finished hand-over; driver / POS shift / aggregator ids name
            // back-office rows that are not imported (J58c).
            ignore: ['pod_otp', 'driver_id', 'pos_shift_id', 'hs_order_id', 'hs_order_code', 'hs_transport_type', 'hs_picking_mode'],
            uses: ['currency_code'],
            transform: HistoryImporter::order(...),
        );
    }

    public static function orderItems(): TableMap
    {
        $same = ['item_id', 'variation', 'quantity', 'order_note', 'price_label', 'combo_id', 'station_id', 'status', 'course', 'options', 'seat', 'weight_kg', 'price_schedule_id'];
        $dates = ['started_at', 'item_ready_at', 'served_at', 'fired_at', 'recalled_at'];

        return new TableMap(
            source: 'order_items',
            target: 'order_items',
            scope: TableMap::CHILD,
            parentColumn: 'order_id',
            parentTarget: 'orders',
            columns: array_combine($same, $same),
            dates: array_combine($dates, $dates),
            bools: ['allergy_flag' => 'allergy_flag'],
            // A deleted menu item: the order line is kept with item_id NULL (plan §4). The recorded unit price already
            // carries any scheduled / combo price; the schedule and combo ids are kept when imported for this store.
            refs: ['item_id' => 'items', 'combo_id' => 'combos', 'station_id' => 'kitchen_stations', 'price_schedule_id' => 'price_schedules'],
            currency: true,
            uses: ['unit_price'],
            transform: HistoryImporter::orderLine(...),
        );
    }

    public static function orderItemExtras(): TableMap
    {
        return new TableMap(
            source: 'order_item_extras',
            target: 'order_item_extras',
            scope: TableMap::CHILD,
            parentColumn: 'order_item_id',
            parentTarget: 'order_items',
            columns: ['extra_id' => 'extra_id', 'quantity' => 'quantity'],
            // The extra is a modifier of this install only once the catalogue's extras are imported with their ids.
            refs: ['extra_id' => 'modifiers'],
            currency: true,
            uses: ['price'],
            transform: HistoryImporter::lineExtra(...),
        );
    }

    public static function loyaltyPrograms(): TableMap
    {
        return new TableMap(
            source: 'loyalty_programs',
            target: 'loyalty_programs',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'type' => 'type', 'earn_rate' => 'earn_rate', 'reward_threshold' => 'reward_threshold',
                'reward_description' => 'reward_description', 'tiers' => 'tiers',
            ],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['active' => 'active'],
            money: ['reward_discount_minor' => 'reward_discount'],
            currency: true,
        );
    }

    public static function loyaltyAccounts(): TableMap
    {
        return new TableMap(
            source: 'loyalty_accounts',
            target: 'loyalty_accounts',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'customer_id' => 'customer_id', 'balance' => 'balance', 'lifetime_earned' => 'lifetime_earned',
                'lifetime_redeemed' => 'lifetime_redeemed',
            ],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            requiredRefs: ['customer_id' => 'customers'],
        );
    }

    public static function loyaltyLedger(): TableMap
    {
        return new TableMap(
            source: 'loyalty_ledger',
            target: 'loyalty_ledger_entries',
            scope: TableMap::CHILD,
            parentColumn: 'loyalty_account_id',
            parentTarget: 'loyalty_accounts',
            columns: [
                'order_id' => 'order_id', 'redeemed_order_id' => 'redeemed_order_id', 'type' => 'type', 'delta' => 'delta',
                'balance_after' => 'balance_after', 'note' => 'note',
            ],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            refs: ['order_id' => 'orders', 'redeemed_order_id' => 'orders'],
        );
    }

    public static function giftCards(): TableMap
    {
        return new TableMap(
            source: 'gift_cards',
            target: 'gift_cards',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'code' => 'code', 'currency' => 'currency', 'status' => 'status',
                'purchaser_customer_id' => 'purchaser_customer_id', 'purchaser_email' => 'purchaser_email',
                'recipient_email' => 'recipient_email', 'recipient_phone' => 'recipient_phone', 'message' => 'message',
                'design' => 'design',
            ],
            dates: [
                'issued_at' => 'issued_at', 'scheduled_send_at' => 'scheduled_send_at', 'delivered_at' => 'delivered_at',
                'expires_at' => 'expires_at', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
            ],
            refs: ['purchaser_customer_id' => 'customers'],
            // The funding payment row is platform billing history (not imported by this loader set).
            ignore: ['transaction_id'],
            uses: ['initial_value'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                // Minor units of the card's OWN currency.
                $exponent = $ctx->exponentOf((string) ($out['currency'] ?? ''));
                if ($exponent === null) {
                    return 'gift card currency unknown';
                }
                $minor = $ctx->minor($row['initial_value'] ?? null, $exponent);
                if ($minor === null) {
                    return 'gift card value not numeric';
                }
                $out['currency'] = strtoupper((string) $out['currency']);
                $out['initial_value_minor'] = $minor;

                return $out;
            },
        );
    }

    public static function storeDomains(): TableMap
    {
        return new TableMap(
            source: self::DOMAINS,
            target: 'store_domains',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: ['host' => ['domain', 'host'], 'verification_token' => 'verification_token'],
            // The source's verified / TLS / primary state is never trusted (plan §3, R5): the domain re-verifies here.
            ignore: ['ssl_status', 'is_primary', 'type', 'account_id', 'created_at', 'updated_at'],
            uses: ['verified', 'verified_at'],
            transform: static fn (array $out, array $row, ImportContext $ctx): array|string => StoreDomainRules::apply($out, $row, $ctx),
        );
    }

    /** Hotel room types (rooms are store tables that name one; rate plans, stays and bookings: {@see ScopeMaps}). */
    public static function roomTypes(): TableMap
    {
        return new TableMap(
            source: 'room_types',
            target: 'room_types',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: ['name' => 'name', 'code' => 'code', 'max_occupancy' => 'max_occupancy', 'sort' => 'sort'],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['active' => 'active'],
            money: ['base_rate_minor' => 'base_rate'],
            currency: true,
        );
    }

    /**
     * Store staff logins. Only a PIN HASH is stored, never a plaintext PIN (a plaintext-only PIN is hashed on import);
     * two-factor secrets are not carried over (J58b, marked to set up again). HR and payroll fields (identity number,
     * date of birth, address, emergency contact, contract, wages) are back-office data, not imported (J58c). The owner
     * is the store's owner (derived, never taken from the row).
     */
    public static function staff(): TableMap
    {
        return new TableMap(
            source: 'restaurant_staff',
            target: 'staff_members',
            scope: TableMap::STORE,
            storeColumns: self::STORE_KEYS,
            columns: [
                'branch_id' => 'branch_id', 'default_branch_id' => 'default_branch_id', 'station_id' => 'station_id',
                'name' => 'name', 'email' => 'email', 'password' => 'password', 'pin_hash' => 'pin_hash', 'role' => 'role',
                'permissions' => 'permissions',
            ],
            dates: ['last_login_at' => 'last_login_at', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['active' => 'active'],
            refs: ['branch_id' => 'branches', 'default_branch_id' => 'branches', 'station_id' => 'kitchen_stations'],
            ignore: [
                'pin', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
                'national_id', 'date_of_birth', 'address', 'emergency_contact', 'emergency_phone', 'employment_start',
                'contract_type', 'base_wage', 'hourly_rate',
            ],
            uses: ['owner_id'],
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                $id = (int) $out['id'];
                $owner = (int) $ctx->target->table('stores')->where('id', (int) $ctx->storeId)->value('user_id');
                if ((int) ($row['owner_id'] ?? 0) !== $owner) {
                    $ctx->report->conflict('staff_members.owner_id: not the store owner in the source, store owner used', 'restaurant_staff#'.$id);
                }
                $out['owner_id'] = $owner;
                if (trim((string) ($out['pin_hash'] ?? '')) === '') {
                    $out['pin_hash'] = null;
                    $pin = trim((string) ($row['pin'] ?? ''));
                    if ($pin !== '') {
                        // A member with only a plaintext PIN keeps signing in: hashed here, the plaintext is never stored.
                        $out['pin_hash'] = Hash::make($pin);
                        $ctx->report->count('staff_pin_hashed_at_import');
                    }
                }
                if (trim((string) ($row['two_factor_secret'] ?? '')) !== '' || ($row['two_factor_confirmed_at'] ?? null) !== null) {
                    $ctx->mark('staff_members', $id, self::TWO_FACTOR_REENROL);
                    $ctx->report->count('staff_two_factor_reenrol');
                }

                return $out;
            },
        );
    }

    /**
     * The per-store loaders in write order (parents first).
     *
     * @return list<TableMap>
     */
    public static function perStore(): array
    {
        return [
            // Staff and room types before the tables that name them; store allergies before the items that list them.
            self::branches(), self::staff(), self::roomTypes(), self::storeTables(),
            // J59g hotel: rate plans and room rates after the room types, open stays after the rooms (tables).
            ...ScopeMaps::afterTables(), self::storeAllergies(), self::categories(), self::items(),
            // Variants, option groups and add-ons right after the items (combo options, order lines and their extras
            // name them); combos and price schedules before the order lines that name them.
            ...ModifierMaps::catalogue(), ...LegacyContentMaps::catalogue(),
            self::customers(), ...LegacyContentMaps::customerData(), ...ScopeMaps::afterCustomers(), ...MarketplaceMaps::perStore(),
            // Gift cards before orders: an order's gift_card_id must resolve (the card balance derives from it).
            self::giftCards(), self::orders(), self::orderItems(), self::orderItemExtras(), self::loyaltyPrograms(),
            self::loyaltyAccounts(), self::loyaltyLedger(), ...LegacyContentMaps::afterOrders(), ...ScopeMaps::afterOrders(),
            self::storeDomains(),
        ];
    }

    /**
     * The owner's platform wallet ledger (minor units of the platform currency). Imported only for users whose ledger
     * sums to their balance ({@see LedgerChecks}); a row of a user not imported is skipped.
     */
    public static function walletEntries(): TableMap
    {
        return new TableMap(
            source: LedgerChecks::WALLET,
            target: 'wallet_transactions',
            scope: TableMap::PLATFORM,
            columns: ['user_id' => 'user_id', 'type' => 'type', 'reason' => 'reason', 'ref' => 'ref', 'granted_by' => 'granted_by'],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            money: ['amount_minor' => 'amount', 'balance_after_minor' => 'balance_after'],
            uses: ['currency_code'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $user = (int) ($out['user_id'] ?? 0);
                if (! $ctx->resolves('users', $user)) {
                    return 'user not imported';
                }
                if (! isset($out['amount_minor'], $out['balance_after_minor'])) {
                    return 'amount not numeric';
                }
                if (! in_array($out['type'] ?? null, ['credit', 'debit'], true)) {
                    $out['type'] = (int) $out['amount_minor'] < 0 ? 'debit' : 'credit';
                }
                $out['user_id'] = $user;
                $out['account_id'] = $ctx->accounts[$user] ?? null;
                $out['currency_code'] = $ctx->platformCurrency;

                return $out;
            },
        );
    }

    /**
     * Whether a source billing row is an order payment (imported with its store, see {@see ORDER_PAYMENT}).
     *
     * @param  array<string, mixed>  $row
     */
    public static function isOrderPayment(array $row): bool
    {
        return ($row['transaction_method'] ?? null) === self::ORDER_PAYMENT;
    }

    /**
     * Platform billing payments (plans, add-ons, top-ups) of a user: history in the row's own currency. Order payments
     * are not loaded through this map ({@see orderPayments()}).
     */
    public static function billingTransactions(): TableMap
    {
        return self::transactions(TableMap::PLATFORM);
    }

    /**
     * Order payments of the store being imported (source `user_id` = the store id): store and account from the
     * store, no user.
     */
    public static function orderPayments(): TableMap
    {
        return self::transactions(TableMap::STORE);
    }

    private static function transactions(string $scope): TableMap
    {
        return new TableMap(
            source: 'transaction',
            target: 'transaction',
            scope: $scope,
            storeColumns: $scope === TableMap::STORE ? ['user_id'] : [],
            columns: [
                'product_name' => 'product_name', 'product_id' => 'product_id', 'user_id' => 'user_id', 'status' => 'status',
                'payment_id' => 'payment_id', 'transaction_gatway' => 'transaction_gatway',
                'transaction_method' => 'transaction_method', 'transaction_description' => 'transaction_description',
                'transaction_ip' => 'transaction_ip', 'frequency' => 'frequency', 'billing' => 'billing',
                'taxes_ids' => 'taxes_ids', 'details' => 'details', 'coupon' => 'coupon', 'invoice_number' => 'invoice_number',
                'featured' => 'featured', 'urgent' => 'urgent', 'highlight' => 'highlight',
            ],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'credited_at' => 'credited_at'],
            uses: ['amount', 'base_amount', 'currency_code'],
            transform: static function (array $out, array $row, ImportContext $ctx) use ($scope): array|string {
                $out = $scope === TableMap::STORE ? self::orderPaymentOwner($out, $ctx) : self::billingOwner($out, $ctx);
                if (is_string($out)) {
                    return $out;
                }
                $out = self::billingAmounts($out, $row, $ctx);
                if (is_string($out)) {
                    return $out;
                }
                // Unique keys of the target (payment_id + gateway, invoice_number): an empty source value means "none".
                foreach (['payment_id', 'transaction_gatway', 'invoice_number'] as $key) {
                    if (array_key_exists($key, $out) && trim((string) $out[$key]) === '') {
                        $out[$key] = null;
                    }
                }
                $frequency = strtoupper(trim((string) ($out['frequency'] ?? '')));
                $out['frequency'] = in_array($frequency, ['MONTHLY', 'YEARLY', 'LIFETIME'], true) ? $frequency : null;
                foreach (['featured', 'urgent', 'highlight'] as $flag) {
                    if (array_key_exists($flag, $out)) {
                        $out[$flag] = in_array((string) $out[$flag], ['1', 'true'], true) ? '1' : '0';
                    }
                }

                return $out;
            },
        );
    }

    /** Platform billing receipts of a user (one per paid transaction): history in the row's own currency. */
    public static function billingReceipts(): TableMap
    {
        return self::receipts(TableMap::PLATFORM);
    }

    /** Receipts of the store's order payments (source `user_id` = the store id). */
    public static function orderReceipts(): TableMap
    {
        return self::receipts(TableMap::STORE);
    }

    private static function receipts(string $scope): TableMap
    {
        return new TableMap(
            source: 'billing_receipts',
            target: 'billing_receipts',
            scope: $scope,
            storeColumns: $scope === TableMap::STORE ? ['user_id'] : [],
            columns: [
                'user_id' => 'user_id', 'transaction_id' => 'transaction_id', 'transaction_method' => 'transaction_method',
                'product_name' => 'product_name', 'product_id' => 'product_id', 'plan_id' => 'plan_id',
                'addon_type' => 'addon_type', 'gateway' => 'gateway', 'gateway_ref' => 'gateway_ref', 'details' => 'details',
            ],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            refs: ['transaction_id' => 'transaction'],
            uses: ['amount', 'base_amount', 'currency_code'],
            transform: static function (array $out, array $row, ImportContext $ctx) use ($scope): array|string {
                $out = $scope === TableMap::STORE ? self::orderPaymentOwner($out, $ctx) : self::billingOwner($out, $ctx);
                if (is_string($out)) {
                    return $out;
                }
                // A receipt names a payment of the SAME owner: this store's order payments (store scope) or a
                // user's billing (platform scope) — never the other kind, never another store's (J29).
                $transaction = $out['transaction_id'] ?? null;
                if ($transaction !== null) {
                    $ok = $scope === TableMap::STORE ? $ctx->resolvesInStore('transaction', (int) $transaction) : $ctx->resolvesPlatform('transaction', (int) $transaction);
                    if (! $ok) {
                        $out['transaction_id'] = null;
                        $ctx->report->conflict('billing_receipts.transaction_id: payment of another owner or not imported, set NULL', 'billing_receipts#'.(int) $out['id']);
                    }
                }

                return self::billingAmounts($out, $row, $ctx);
            },
        );
    }

    /**
     * An order payment (or its receipt) belongs to the store being imported, never to a user: the source `user_id`
     * held the store id. The account and store come from the loader (J29-1).
     *
     * A row whose order (`product_id`) is a source order of ANOTHER store is refused: its billing details belong to that
     * store's customer (J29). One whose order is this store's, or no longer exists in the source, is kept as history.
     *
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>|string
     */
    private static function orderPaymentOwner(array $out, ImportContext $ctx): array|string
    {
        $out['user_id'] = null;
        $order = $out['product_id'] ?? null;
        if ($order === null || $order === '' || $ctx->resolvesInStore('orders', (int) $order)) {
            return $out;
        }
        $source = $ctx->source->find('orders', (int) $order);
        $column = $source === null ? null : $ctx->source->pick('orders', self::STORE_KEYS);
        if ($source !== null && $column !== null && (int) ($source[$column] ?? 0) !== (int) $ctx->storeId) {
            return "order payment names another store's order";
        }
        $ctx->report->conflict('order payments: product_id names no order imported for this store (kept as history)', 'store#'.(int) $ctx->storeId.' row#'.(int) $out['id']);

        return $out;
    }

    /**
     * A billing row names an imported user (or none): the account is the user's own.
     *
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>|string
     */
    private static function billingOwner(array $out, ImportContext $ctx): array|string
    {
        $user = $out['user_id'] ?? null;
        if ($user === null || $user === '' || (int) $user === 0) {
            $out['user_id'] = null;
            $out['account_id'] = null;
            $out['store_id'] = null;

            return $out;
        }
        if (! $ctx->resolves('users', (int) $user)) {
            return 'user not imported';
        }
        $out['user_id'] = (int) $user;
        $out['account_id'] = $ctx->accounts[(int) $user] ?? null;
        $out['store_id'] = null;

        return $out;
    }

    /**
     * Amounts in minor units of the row's OWN currency; a row naming none is in its owner's currency: the store's for an
     * order payment (store scope), the platform's for a user's billing.
     *
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private static function billingAmounts(array $out, array $row, ImportContext $ctx): array|string
    {
        $currency = strtoupper(trim((string) ($row['currency_code'] ?? '')));
        if ($currency === '') {
            $currency = (string) ($ctx->storeId !== null ? $ctx->currency : $ctx->platformCurrency);
        }
        $exponent = $ctx->exponentOf($currency);
        if ($exponent === null) {
            return 'billing currency unknown';
        }
        $amount = $ctx->minor($row['amount'] ?? null, $exponent);
        if ($amount === null) {
            return 'billing amount not numeric';
        }
        $base = $row['base_amount'] ?? null;
        $out['amount_minor'] = $amount;
        $out['base_amount_minor'] = $base === null || $base === '' ? null : $ctx->minor($base, $exponent);
        if ($out['base_amount_minor'] === null && $base !== null && $base !== '') {
            $ctx->report->conflict('billing: base amount not numeric, set NULL', 'row#'.(int) $out['id']);
        }
        $out['currency_code'] = $currency;

        return $out;
    }

    /**
     * Platform history loaders in write order (after users).
     *
     * @return list<TableMap>
     */
    public static function platformHistory(): array
    {
        return [self::walletEntries(), self::billingTransactions(), self::billingReceipts()];
    }

    /**
     * Every loader (platform, then stores).
     *
     * @return list<TableMap>
     */
    public static function all(): array
    {
        return [
            self::users(), self::allergies(), ...LegacyContentMaps::platform(), ...PlatformContentMaps::platform(), ...ModifierMaps::platform(), ...self::platformHistory(),
            MarketplaceMaps::shoppers(), MarketplaceMaps::walletEntries(), ...MarketplaceMaps::platform(), self::stores(), ...self::perStore(),
        ];
    }

    /** @return list<string> source tables covered by a map or handled by the importer */
    public static function covered(): array
    {
        $maps = self::all();

        return array_values(array_unique(array_merge(
            array_map(static fn (TableMap $m): string => $m->source, $maps),
            self::HANDLED_ELSEWHERE,
        )));
    }
}
