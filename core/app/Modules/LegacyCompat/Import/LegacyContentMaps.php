<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use App\Models\Coupon;

/**
 * The must-move later-lineage tables (DDL diff §3, §5 #9): store content (old slugs, stories, theme settings, custom
 * and bio blocks, QR designs, image menus, media rows), messaging consent and opt-outs (legal: an opt-out that is
 * dropped would re-enable messages), pending account deletions (a user inside the purge window must not come back),
 * coupons (multi-scope) and their redemptions, customer addresses and follows, reviews, branch item overrides, combos
 * and price schedules. Ids are kept; every store row gets its account and store from the loader (J29-1); every
 * reference must name a row of the same store (or an imported platform row), else NULL / skipped and reported.
 *
 * Media files themselves are copied by the media copier (M6); here only their rows.
 */
final class LegacyContentMaps
{
    /** Coupon issuers: an owner's (all their stores, or one branch), a reseller's, or the platform's. */
    public const COUPON_OWNER = 'owner';

    public const COUPON_RESELLER = 'reseller';

    public const COUPON_PLATFORM = 'platform';

    /** Longest coupon code this install stores (the target column). */
    public const COUPON_CODE_MAX = 20;

    private const TIMESTAMPS = ['created_at' => 'created_at', 'updated_at' => 'updated_at'];

    /**
     * Platform-phase loaders (after users, before stores).
     *
     * @return list<TableMap>
     */
    public static function platform(): array
    {
        return [
            self::optouts('sms_optouts', 'sms_optouts', TableMap::PLATFORM),
            self::optouts('whatsapp_optouts', 'whatsapp_optouts', TableMap::PLATFORM),
            self::accountDeletions(),
            self::coupons(),
            self::couponTerms(),
            self::promotionRedemptions(),
        ];
    }

    /**
     * Store catalogue extras, loaded after items and before customers and orders (order lines name combos and
     * price schedules).
     *
     * @return list<TableMap>
     */
    public static function catalogue(): array
    {
        return [
            self::mediaAssets(), self::branchMenu(), self::combos(), self::comboSlots(), self::comboSlotOptions(),
            self::priceSchedules(), self::priceScheduleTargets(),
        ];
    }

    /** @return list<TableMap> loaded right after the store's customers */
    public static function customerData(): array
    {
        return [self::customerAddresses(), self::storeFollows()];
    }

    /** @return list<TableMap> loaded after the store's orders (they name orders, customers, items) */
    public static function afterOrders(): array
    {
        return [
            self::couponRedemptions(), self::menuReviews(), self::menuReviewMedia(), self::storeReviews(),
            self::storeReviewMedia(), self::slugRedirects(), self::optouts('sms_optouts', 'sms_optouts', TableMap::STORE),
            self::optouts('whatsapp_optouts', 'whatsapp_optouts', TableMap::STORE), self::whatsappConsents(),
            self::stories(), self::themeSettings(), self::storeCustomBlocks(), self::bioBlocks(), self::qrDesigns(),
            self::imageMenus(),
        ];
    }

    /** Old slugs of a store: printed QR codes and links of a renamed store still reach it. */
    public static function slugRedirects(): TableMap
    {
        return new TableMap(
            source: 'slug_redirects', target: 'slug_redirects', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['old_slug' => 'old_slug'], dates: self::TIMESTAMPS,
        );
    }

    /**
     * SMS / WhatsApp opt-outs. A row with no store is platform-wide (platform phase), a store's row comes with its
     * store. Never dropped silently: an opt-out lost here would message someone who said stop.
     */
    public static function optouts(string $source, string $target, string $scope): TableMap
    {
        return new TableMap(
            source: $source, target: $target, scope: $scope, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['phone' => 'phone', 'source' => 'source'], dates: self::TIMESTAMPS,
        );
    }

    public static function whatsappConsents(): TableMap
    {
        return new TableMap(
            source: 'whatsapp_consent', target: 'whatsapp_consents', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['phone' => 'phone', 'source' => 'source'],
            dates: ['opted_in_at' => 'opted_in_at', 'opted_out_at' => 'opted_out_at'] + self::TIMESTAMPS,
        );
    }

    /**
     * Account deletions in progress: the user stays inside the purge window here (and is purged when it ends). A row
     * of a user not imported is skipped.
     */
    public static function accountDeletions(): TableMap
    {
        return new TableMap(
            source: 'account_deletions', target: 'account_deletions', scope: TableMap::PLATFORM,
            columns: [
                'user_id' => 'user_id', 'status' => 'status', 'email_hash' => 'email_hash', 'reason' => 'reason',
                'requested_ip' => 'requested_ip', 'source' => 'source', 'previous_status' => 'previous_status',
                'purge_report' => 'purge_report',
            ],
            dates: [
                'requested_at' => 'requested_at', 'purge_after' => 'purge_after', 'restored_at' => 'restored_at',
                'purged_at' => 'purged_at',
            ] + self::TIMESTAMPS,
            requiredRefs: ['user_id' => 'users'],
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                // A deletion still in its window keeps the user switched off here (as the source did on request).
                if (($out['status'] ?? null) === 'pending') {
                    $ctx->target->table('users')->where('id', (int) $out['user_id'])->update(['status' => 0]);
                    $ctx->report->count('users_disabled_pending_deletion');
                }

                return $out;
            },
        );
    }

    /**
     * Coupons of every issuer (the source has owner, reseller and platform coupons, some limited to one branch).
     * Owner coupons keep their owner (account derived from it) and are in the currency of the owner's first store; a
     * branch must be a branch of one of the owner's stores. Reseller coupons are kept only when the reseller is on this
     * install: never turned into platform coupons. Codes longer than this install stores are skipped (reported).
     */
    public static function coupons(): TableMap
    {
        $same = [
            'applies_to', 'per_customer_limit', 'code', 'limit', 'used', 'coupon_type', 'applies_interval', 'buy_scope',
            'buy_id', 'buy_qty', 'get_id', 'get_qty', 'get_discount_percent',
        ];

        return new TableMap(
            source: 'coupons', target: 'coupons', scope: TableMap::PLATFORM,
            columns: array_combine($same, $same),
            dates: ['expiry_at' => 'expiry_at'] + self::TIMESTAMPS,
            bools: ['first_period_only' => 'first_period_only'],
            // A coupon batch is an issuing convenience; its codes are the coupons themselves.
            ignore: ['batch_id'],
            uses: ['user_id', 'issuer_scope', 'reseller_id', 'branch_id', 'percentage', 'min_order_amount'],
            transform: static fn (array $out, array $row, ImportContext $ctx): array|string => self::coupon($out, $row, $ctx),
        );
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private static function coupon(array $out, array $row, ImportContext $ctx): array|string
    {
        $id = (int) $out['id'];
        $code = trim((string) ($out['code'] ?? ''));
        if ($code === '' || mb_strlen($code) > self::COUPON_CODE_MAX) {
            return 'coupon code empty or longer than '.self::COUPON_CODE_MAX.' characters';
        }
        $user = (int) ($row['user_id'] ?? 0);
        $reseller = (int) ($row['reseller_id'] ?? 0);
        $out['user_id'] = null;
        $out['reseller_id'] = null;
        $out['branch_id'] = null;
        $out['account_id'] = null;
        if ($user > 0) {
            if (! isset($ctx->accounts[$user]) || ! $ctx->resolves('users', $user)) {
                return 'coupon owner not imported (or owns no store)';
            }
            $out['user_id'] = $user;
            $out['account_id'] = $ctx->accounts[$user];
            $out['issuer_scope'] = self::COUPON_OWNER;
            $currency = self::ownerCurrency($user, $ctx);
            $branch = (int) ($row['branch_id'] ?? 0);
            if ($branch > 0) {
                if (! self::ownersBranch($branch, $user, $ctx)) {
                    return "coupon branch is not a branch of the owner's stores";
                }
                $out['branch_id'] = $branch;
            }
        } elseif ($reseller > 0) {
            $kept = LegacyTableMaps::reseller($reseller, 'coupons', $id, $ctx);
            if ($kept === null) {
                return 'reseller coupon: reseller not on this install (import it with the reseller)';
            }
            $out['reseller_id'] = $kept;
            $out['issuer_scope'] = self::COUPON_RESELLER;
            $currency = $ctx->platformCurrency;
        } else {
            $out['issuer_scope'] = self::COUPON_PLATFORM;
            $currency = $ctx->platformCurrency;
        }
        $exponent = $ctx->exponentOf($currency);
        if ($currency === null || $exponent === null) {
            return 'coupon currency unknown';
        }
        $out['currency_code'] = strtoupper($currency);
        $min = $row['min_order_amount'] ?? null;
        $out['min_order_amount_minor'] = $min === null || $min === '' ? null : $ctx->minor($min, $exponent);
        $value = $row['percentage'] ?? null;
        if (Coupon::typeIsAmount((string) ($out['coupon_type'] ?? ''))) {
            $out['amount_minor'] = $ctx->minor($value ?? 0, $exponent);
            $out['percentage_rate'] = null;
        } else {
            $out['percentage_rate'] = is_numeric($value) ? (string) $value : '0';
            $out['amount_minor'] = null;
        }
        // Buy-X-get-Y targets must be the issuing owner's own item / category (checked on the source: stores load later).
        $targets = ['buy_id' => ($out['buy_scope'] ?? 'item') === 'category' ? 'menu_categories' : 'menu', 'get_id' => 'menu'];
        foreach ($targets as $column => $table) {
            $target = (int) ($out[$column] ?? 0);
            if ($target > 0 && ($user <= 0 || ! self::ownersRow($table, $target, $user, $ctx))) {
                $out[$column] = null;
                $ctx->report->conflict('coupons.'.$column.": not an item / category of the issuing owner's stores, set NULL", 'coupons#'.$id);
            }
        }
        $out['issuer_key'] = Coupon::issuerKey($out['user_id'], $out['reseller_id']);
        $out['code_key'] = Coupon::codeKey($code);
        $out['code'] = $code;

        return $out;
    }

    /** Promotion targets of coupon terms (the offers engine). */
    private const PROMO_TARGETS = ['platform_plan', 'reseller_plan', 'reseller_tier'];

    /**
     * Promotion terms of imported coupons (plans, intervals, window, automatic mode, new accounts, per-account limit,
     * display text, priority). Kept only with their coupon and only when the issuer matches the target (a platform
     * coupon for platform-plan and reseller-tier sales, the reseller's coupon for its own plans). Platform plan ids go
     * through the plan map; a terms row whose plans do not all map (or that names reseller tiers or reseller plans, whose ids
     * are not carried over) is kept switched off and limited to no plan, never widened to every plan — reported for review.
     * Gateway coupon references are not carried: they name provider objects and are created again on first use.
     */
    public static function couponTerms(): TableMap
    {
        $same = ['duration', 'duration_months', 'amount_off_minor', 'currency_code', 'per_account_limit', 'priority'];

        return new TableMap(
            source: 'reseller_coupon_terms', target: 'reseller_coupon_terms', scope: TableMap::PLATFORM,
            columns: array_combine($same, $same) + ['coupon_id' => 'coupon_id', 'target' => 'target'],
            dates: ['starts_at' => 'starts_at'] + self::TIMESTAMPS,
            bools: ['auto_apply' => 'auto_apply', 'new_accounts_only' => 'new_accounts_only'],
            requiredRefs: ['coupon_id' => 'coupons'],
            ignore: ['gateway_refs'],
            uses: ['reseller_id', 'plan_ids', 'intervals', 'display'],
            transform: static fn (array $out, array $row, ImportContext $ctx): array|string => self::couponTerm($out, $row, $ctx),
        );
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    private static function couponTerm(array $out, array $row, ImportContext $ctx): array|string
    {
        $id = (int) ($out['id'] ?? 0);
        $target = (string) ($out['target'] ?? 'reseller_plan');
        if (! in_array($target, self::PROMO_TARGETS, true)) {
            return 'unknown promotion target';
        }
        $out['target'] = $target;
        $resellerId = $ctx->target->table('coupons')->where('id', (int) $out['coupon_id'])->value('reseller_id');
        $resellerId = $resellerId === null ? null : (int) $resellerId;
        if (($target === 'reseller_plan') !== ($resellerId !== null)) {
            return 'the coupon issuer does not match the promotion target';
        }
        $out['reseller_id'] = $resellerId;
        if (! in_array($out['duration'] ?? null, ['once', 'repeating', 'forever'], true)) {
            $out['duration'] = 'once';
        }
        $out['currency_code'] = ($out['currency_code'] ?? null) ? strtoupper((string) $out['currency_code']) : null;
        foreach (['intervals', 'display'] as $json) {
            $out[$json] = self::jsonOrNull($row[$json] ?? null, $json, $id, $ctx);
        }

        $plans = json_decode((string) ($row['plan_ids'] ?? ''), true);
        $plans = is_array($plans) ? array_values(array_filter(array_map('intval', $plans), static fn (int $p): bool => $p > 0)) : [];
        if ($plans === []) {
            $out['plan_ids'] = null; // every plan of the target, as at the source

            return $out;
        }
        if ($target === 'reseller_plan') {
            /* BL-038 review: reseller plan ids are not proven to survive the reseller import with the same ids — kept
               switched off for no plan (like tier ids), never applied to whatever plan holds the id here. */
            $out['plan_ids'] = '[0]';
            $out['auto_apply'] = false;
            $ctx->report->conflict('reseller_coupon_terms: reseller plan ids are not carried over: kept switched off for no plan, review the offer', 'reseller_coupon_terms#'.$id);

            return $out;
        }
        $mapped = [];
        if ($target === 'platform_plan') {
            foreach ($plans as $plan) {
                $to = PlanMemberships::target((string) $plan, 'reseller_coupon_terms#'.$id, $ctx);
                if ($to !== null) {
                    $mapped[] = $to;
                }
            }
        }
        if ($target === 'reseller_tier' || count($mapped) !== count($plans)) {
            $out['plan_ids'] = '[0]';
            $out['auto_apply'] = false;
            $ctx->report->conflict('reseller_coupon_terms: plans not all mapped to this install (reseller tiers or unmapped plans): kept switched off for no plan, review the offer', 'reseller_coupon_terms#'.$id);

            return $out;
        }
        $out['plan_ids'] = (string) json_encode(array_values(array_unique($mapped)));

        return $out;
    }

    /** A source JSON column as valid JSON text, or null (invalid JSON reported). */
    private static function jsonOrNull(mixed $value, string $column, int $id, ImportContext $ctx): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        if (! is_array($decoded)) {
            $ctx->report->conflict('reseller_coupon_terms.'.$column.': not valid JSON, set NULL', 'reseller_coupon_terms#'.$id);

            return null;
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Per-account uses of promotions (the per-account limit): a platform account's uses when that account was imported;
     * a reseller's (tier sales) and a reseller customer's go with the reseller import.
     */
    public static function promotionRedemptions(): TableMap
    {
        return new TableMap(
            source: 'promotion_redemptions', target: 'promotion_redemptions', scope: TableMap::PLATFORM,
            columns: ['coupon_id' => 'coupon_id', 'subject_type' => 'subject_type', 'seq' => 'seq', 'reference' => 'reference'],
            dates: ['created_at' => 'created_at'],
            requiredRefs: ['coupon_id' => 'coupons'],
            uses: ['subject_id'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $subject = (int) ($row['subject_id'] ?? 0);
                $out['subject_id'] = $subject;
                // A use imported from the source was paid there: confirmed, never handed back as a stale reservation.
                $out['reference'] = isset($out['reference']) ? 'ok:'.mb_substr((string) $out['reference'], 0, 60) : null;

                return match ((string) ($out['subject_type'] ?? '')) {
                    'user' => $subject > 0 && $ctx->resolves('users', $subject) ? $out : 'promotion use of an account not imported',
                    'reseller', 'reseller_customer' => 'promotion use of a reseller or its customer (imported with the reseller)',
                    default => 'promotion use of an unknown subject',
                };
            },
        );
    }

    /** Currency of the owner's first source store (the issuer's currency of an owner coupon), else the platform's. */
    private static function ownerCurrency(int $user, ImportContext $ctx): ?string
    {
        $source = $ctx->source;
        $store = $source->db->table(LegacyTableMaps::STORES)->where(LegacyTableMaps::STORE_OWNER, $user)->orderBy('id')->value('id');
        $key = $source->pick(LegacyTableMaps::STORE_OPTIONS, LegacyTableMaps::STORE_KEYS);
        if ($store !== null && $key !== null) {
            $code = $source->db->table(LegacyTableMaps::STORE_OPTIONS)->where($key, (int) $store)
                ->where('option_name', LegacyTableMaps::STORE_CURRENCY_OPTION)->value('option_value');
            if (is_string($code) && trim($code) !== '') {
                return strtoupper(trim($code));
            }
        }

        return $ctx->platformCurrency;
    }

    /** Is $branch (source) a branch of a source store owned by $user? */
    private static function ownersBranch(int $branch, int $user, ImportContext $ctx): bool
    {
        return self::ownersRow('branches', $branch, $user, $ctx);
    }

    /** Is row $id of source $table (store-scoped) a row of a source store owned by $user? */
    private static function ownersRow(string $table, int $id, int $user, ImportContext $ctx): bool
    {
        $source = $ctx->source;
        $row = $source->find($table, $id);
        $key = $source->pick($table, LegacyTableMaps::STORE_KEYS);
        if ($row === null || $key === null) {
            return false;
        }
        $owner = $source->db->table(LegacyTableMaps::STORES)->where('id', (int) ($row[$key] ?? 0))->value(LegacyTableMaps::STORE_OWNER);

        return (int) $owner === $user;
    }

    /**
     * Whether an imported coupon may be named by a row of the current store: a platform coupon, or a coupon of the
     * store's own account (never another owner's or a reseller's of another tenant).
     */
    public static function couponOfStore(int $couponId, ImportContext $ctx): bool
    {
        $coupon = $ctx->target->table('coupons')->where('id', $couponId)->first(['account_id', 'reseller_id']);
        if ($coupon === null) {
            return false;
        }

        return $coupon->account_id === null ? $coupon->reseller_id === null : (int) $coupon->account_id === (int) $ctx->accountId;
    }

    /** Per-customer coupon use (per-customer limits rely on it). Rows of this store's customers only. */
    public static function couponRedemptions(): TableMap
    {
        return new TableMap(
            source: 'coupon_redemptions', target: 'coupon_redemptions', scope: TableMap::CHILD,
            parentColumn: 'customer_id', parentTarget: 'customers',
            columns: ['coupon_id' => 'coupon_id', 'seq' => 'seq', 'order_id' => 'order_id'],
            dates: ['created_at' => 'created_at'],
            requiredRefs: ['coupon_id' => 'coupons'],
            refs: ['order_id' => 'orders'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                return self::couponOfStore((int) $out['coupon_id'], $ctx) ? $out : 'coupon of another owner or reseller';
            },
        );
    }

    public static function customerAddresses(): TableMap
    {
        return new TableMap(
            source: 'customer_addresses', target: 'customer_addresses', scope: TableMap::CHILD,
            parentColumn: 'customer_id', parentTarget: 'customers',
            columns: ['label' => 'label', 'line' => 'line', 'city' => 'city', 'state' => 'state', 'zip' => 'zip', 'country' => 'country', 'lat' => 'lat', 'lng' => 'lng'],
            dates: self::TIMESTAMPS, bools: ['is_default' => 'is_default'],
        );
    }

    /** Store follows: the follower must be a customer of the same store. */
    public static function storeFollows(): TableMap
    {
        return new TableMap(
            source: 'restaurant_follows', target: 'store_follows', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['customer_id' => 'customer_id'], dates: self::TIMESTAMPS, bools: ['notify' => 'notify'],
            requiredRefs: ['customer_id' => 'customers'],
        );
    }

    /** Media rows of the store (files are copied by the media copier). */
    public static function mediaAssets(): TableMap
    {
        return new TableMap(
            source: 'media_assets', target: 'media_assets', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: [
                'filename' => 'filename', 'original_name' => 'original_name', 'folder' => 'folder', 'source' => 'source',
                'width' => 'width', 'height' => 'height', 'size_bytes' => 'size_bytes', 'attribution' => 'attribution',
            ],
            dates: self::TIMESTAMPS,
        );
    }

    public static function menuReviews(): TableMap
    {
        return new TableMap(
            source: 'menu_reviews', target: 'menu_reviews', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: [
                'menu_id' => 'menu_id', 'order_id' => 'order_id', 'customer_id' => 'customer_id', 'reviewer_name' => 'reviewer_name',
                'stars' => 'stars', 'body' => 'body', 'status' => 'status', 'session_hash' => 'session_hash',
            ],
            dates: self::TIMESTAMPS, bools: ['is_verified' => 'is_verified'],
            requiredRefs: ['menu_id' => 'items'],
            refs: ['order_id' => 'orders', 'customer_id' => 'customers'],
        );
    }

    public static function menuReviewMedia(): TableMap
    {
        return self::reviewMedia('menu_review_media', 'menu_reviews');
    }

    public static function storeReviews(): TableMap
    {
        return new TableMap(
            source: 'store_reviews', target: 'store_reviews', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: [
                'customer_id' => 'customer_id', 'order_id' => 'order_id', 'reviewer_name' => 'reviewer_name', 'stars' => 'stars',
                'criteria' => 'criteria', 'body' => 'body', 'status' => 'status', 'session_hash' => 'session_hash',
                'helpful_count' => 'helpful_count', 'owner_reply' => 'owner_reply',
            ],
            dates: ['owner_replied_at' => 'owner_replied_at'] + self::TIMESTAMPS, bools: ['is_verified' => 'is_verified'],
            refs: ['order_id' => 'orders', 'customer_id' => 'customers'],
        );
    }

    public static function storeReviewMedia(): TableMap
    {
        return self::reviewMedia('store_review_media', 'store_reviews');
    }

    private static function reviewMedia(string $table, string $reviews): TableMap
    {
        return new TableMap(
            source: $table, target: $table, scope: TableMap::CHILD, parentColumn: 'review_id', parentTarget: $reviews,
            columns: ['media_asset_id' => 'media_asset_id', 'position' => 'position'], dates: self::TIMESTAMPS,
            requiredRefs: ['media_asset_id' => 'media_assets'],
        );
    }

    public static function stories(): TableMap
    {
        return new TableMap(
            source: 'stories', target: 'stories', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['media_url' => 'media_url', 'caption' => 'caption', 'cta_label' => 'cta_label', 'cta_url' => 'cta_url', 'sort' => 'sort'],
            dates: ['starts_at' => 'starts_at', 'expires_at' => 'expires_at'] + self::TIMESTAMPS, bools: ['active' => 'active'],
        );
    }

    /** Theme settings per store and template; theme versions are this install's, so the source version id is dropped. */
    public static function themeSettings(): TableMap
    {
        return new TableMap(
            source: 'theme_settings', target: 'theme_settings', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['template' => 'template', 'settings' => 'settings', 'draft' => 'draft', 'layout' => 'layout', 'layout_draft' => 'layout_draft'],
            dates: self::TIMESTAMPS,
            ignore: ['theme_version_id'],
            transform: static function (array $out): array {
                $out['settings'] ??= '{}';

                return $out;
            },
        );
    }

    public static function storeCustomBlocks(): TableMap
    {
        return new TableMap(
            source: 'store_custom_blocks', target: 'store_custom_blocks', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: [
                'name' => 'name', 'design_json' => 'design_json', 'html' => 'html', 'published_html' => 'published_html',
                'locale' => 'locale', 'placement' => 'placement', 'status' => 'status', 'updated_by' => 'updated_by',
            ],
            dates: self::TIMESTAMPS,
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                // Only the store's owner is a known editor here; any other user id (staff, an admin) is dropped (J29).
                $owner = (int) $ctx->target->table('stores')->where('id', (int) $ctx->storeId)->value('user_id');
                if (($out['updated_by'] ?? null) !== null && (int) $out['updated_by'] !== $owner) {
                    $out['updated_by'] = null;
                }

                return $out;
            },
        );
    }

    public static function bioBlocks(): TableMap
    {
        return new TableMap(
            source: 'bio_blocks', target: 'bio_blocks', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: [
                'branch_id' => 'branch_id', 'type' => 'type', 'label' => 'label', 'url' => 'url', 'config' => 'config',
                'position' => 'position', 'clicks_cached' => 'clicks_cached',
            ],
            dates: ['visible_from' => 'visible_from', 'visible_until' => 'visible_until'] + self::TIMESTAMPS,
            bools: ['active' => 'active'],
            refs: ['branch_id' => 'branches'],
        );
    }

    public static function qrDesigns(): TableMap
    {
        $same = [
            'name', 'preset', 'fg_color', 'bg_color', 'eye_color', 'gradient_to', 'gradient_type', 'dot_style', 'eye_style',
            'logo_file', 'logo_scale', 'frame', 'frame_text', 'size', 'quiet_zone',
        ];

        return new TableMap(
            source: 'qr_designs', target: 'qr_designs', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: array_combine($same, $same), dates: self::TIMESTAMPS, bools: ['is_default' => 'is_default'],
        );
    }

    public static function imageMenus(): TableMap
    {
        return new TableMap(
            source: 'image_menus', target: 'image_menus', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['name' => 'name', 'image' => 'image', 'active' => 'active', 'position' => 'position'],
        );
    }

    /** Per-branch item availability and price (rows of this store's branches; the item must be this store's). */
    public static function branchMenu(): TableMap
    {
        return new TableMap(
            source: 'branch_menu', target: 'branch_menu', scope: TableMap::CHILD, parentColumn: 'branch_id', parentTarget: 'branches',
            columns: ['menu_id' => 'menu_id'], dates: self::TIMESTAMPS, bools: ['available' => 'available'],
            money: ['price_minor' => 'price'], requiredRefs: ['menu_id' => 'items'], currency: true,
        );
    }

    public static function combos(): TableMap
    {
        return new TableMap(
            source: 'combos', target: 'combos', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['branch_id' => 'branch_id', 'name' => 'name', 'description' => 'description', 'image' => 'image', 'position' => 'position'],
            dates: self::TIMESTAMPS, bools: ['active' => 'active'], money: ['price_minor' => 'price'],
            refs: ['branch_id' => 'branches'], currency: true,
            transform: static function (array $out): array {
                $out['price_minor'] ??= 0;

                return $out;
            },
        );
    }

    public static function comboSlots(): TableMap
    {
        return new TableMap(
            source: 'combo_slots', target: 'combo_slots', scope: TableMap::CHILD, parentColumn: 'combo_id', parentTarget: 'combos',
            columns: ['title' => 'title', 'min_select' => 'min_select', 'max_select' => 'max_select', 'position' => 'position'],
            dates: self::TIMESTAMPS,
        );
    }

    public static function comboSlotOptions(): TableMap
    {
        return new TableMap(
            source: 'combo_slot_options', target: 'combo_slot_options', scope: TableMap::CHILD,
            parentColumn: 'combo_slot_id', parentTarget: 'combo_slots',
            columns: ['combo_id' => 'combo_id', 'menu_id' => 'menu_id', 'variant_id' => 'variant_id', 'position' => 'position'],
            dates: self::TIMESTAMPS, bools: ['active' => 'active'], money: ['upcharge_minor' => 'upcharge'],
            requiredRefs: ['combo_id' => 'combos', 'menu_id' => 'items'],
            // Variants come with the modifier import; until then a variant option is dropped to the item (reported).
            refs: ['variant_id' => 'menu_variants'], currency: true,
            transform: static function (array $out): array {
                $out['upcharge_minor'] ??= 0;

                return $out;
            },
        );
    }

    /**
     * Happy-hour / day-part price rules: a percent stays a rate (not money), an amount or fixed price becomes minor
     * units of the store currency (the target's split by type).
     */
    public static function priceSchedules(): TableMap
    {
        $same = ['name', 'label', 'label_translations', 'scope', 'adjustment_type', 'starts_on', 'ends_on', 'days', 'starts_at', 'ends_at', 'order_types', 'priority'];

        return new TableMap(
            source: 'price_schedules', target: 'price_schedules', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: array_combine($same, $same), dates: self::TIMESTAMPS, bools: ['active' => 'active'],
            currency: true, uses: ['adjustment_value'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $value = $row['adjustment_value'] ?? 0;
                if (! is_numeric($value)) {
                    return 'price schedule adjustment not numeric';
                }
                if (($out['adjustment_type'] ?? 'percent') === 'percent') {
                    $out['adjustment_rate'] = (string) $value;
                    $out['adjustment_minor'] = null;
                } else {
                    $out['adjustment_minor'] = $ctx->minor($value);
                    $out['adjustment_rate'] = null;
                }

                return $out;
            },
        );
    }

    /** A schedule's targets: an item or category of the same store (anything else is skipped). */
    public static function priceScheduleTargets(): TableMap
    {
        return new TableMap(
            source: 'price_schedule_targets', target: 'price_schedule_targets', scope: TableMap::CHILD,
            parentColumn: 'schedule_id', parentTarget: 'price_schedules',
            columns: ['target_type' => 'target_type', 'target_id' => 'target_id'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $table = match ($out['target_type'] ?? null) {
                    'item' => 'items',
                    'category' => 'categories',
                    default => null,
                };
                if ($table === null || ! $ctx->resolves($table, (int) ($out['target_id'] ?? 0))) {
                    return 'schedule target is not an item or category of this store';
                }
                // The target table has no tenancy columns: the schedule carries them.
                unset($out['account_id'], $out['store_id']);

                return $out;
            },
        );
    }
}
