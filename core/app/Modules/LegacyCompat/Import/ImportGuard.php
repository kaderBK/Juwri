<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;

/**
 * Refusal guards of `legacy:import` (plan §1.1 rule 2, §6, R6). The import runs only when ALL hold:
 *  - the LegacyCompat switch (legacy_compat.enabled) AND the import switch (legacy_compat.import.enabled) are on;
 *  - a source connection is named explicitly, exists, and is not the target database;
 *  - the source time zone is configured (plan Q4: never assumed);
 *  - every target table the importer writes is empty, or holds only rows an earlier run of this importer wrote
 *    (resume). Ids are kept, so any other row could collide with a source id.
 *
 * Each failed check is returned as a reason; an empty list means the run may start.
 */
final class ImportGuard
{
    /** Target tables that carry one side-table record per row. */
    public const RECORDED_TABLES = [
        'accounts', 'users', 'allergies', 'stores', 'branches', 'store_tables', 'categories', 'items', 'customers',
        'orders', 'order_items', 'order_item_extras', 'loyalty_programs', 'loyalty_accounts', 'loyalty_ledger_entries',
        'gift_cards', 'wallet_transactions', 'transaction', 'billing_receipts', 'staff_members', 'room_types',
        'slug_redirects', 'sms_optouts', 'whatsapp_optouts', 'whatsapp_consents', 'account_deletions', 'coupons',
        'coupon_redemptions', 'customer_addresses', 'store_follows', 'media_assets', 'menu_reviews', 'menu_review_media',
        'store_reviews', 'store_review_media', 'stories', 'theme_settings', 'store_custom_blocks', 'bio_blocks', 'qr_designs',
        'image_menus', 'branch_menu', 'combos', 'combo_slots', 'combo_slot_options', 'price_schedules', 'price_schedule_targets',
        'menu_variants', 'modifier_groups', 'modifiers', 'item_modifier_group', 'global_menu_extras', 'global_menu_categories',
        'shopper_customers',
        // J59g scope: hotel, reservations, catering, push, storefront keys, page views, platform content.
        'rate_plans', 'room_rates', 'hotel_stays', 'hotel_bookings', 'hotel_booking_nights', 'reservations', 'catering_requests',
        'customer_push_subscriptions', 'push_subscriptions', 'storefront_tokens', 'store_page_views', 'store_page_view_daily',
        'blog_categories', 'blog_posts', 'blog_category_post', 'pages', 'faq_entries',
    ];

    /**
     * Recorded tables of Enterprise modules (marketplace J58d, billing J59c): checked like the others when this install
     * has them; an install without them imports nothing into them (reported by the importer).
     */
    public const OPTIONAL_RECORDED_TABLES = [
        'shoppers', 'shopper_wallet_ledger_entries', 'shopper_addresses', 'shopper_favorites', 'shopper_follows',
        'marketplace_sections',
        // J59c plan memberships (Billing): a subscription / add-on row of this install not written by the import refused.
        'subscriptions', 'account_addons',
        // J59g platform announcements (Platform).
        'announcements',
    ];

    /**
     * Tables this install fills itself (shipped languages, sample testimonials) that the import only adds to with new
     * ids: not guarded, but their sequences move past the added rows like the others.
     */
    public const SEQUENCE_ONLY_TABLES = ['languages', 'testimonials'];

    /**
     * Target tables whose rows must all belong to an imported store (any other row could collide). Written without a
     * side-table record (gateways, credentials), or with one but also by owners between resumed runs (store_domains:
     * a domain an imported store's owner adds between two runs must not block the resume).
     */
    public const STORE_ROW_TABLES = ['store_payment_gateways', 'credentials', 'store_domains'];

    /** @return list<string> reasons the import must not run */
    public static function check(Connection $target, ?string $sourceName): array
    {
        $reasons = [];
        if (! LegacyCompatRoutes::enabled()) {
            $reasons[] = 'the LegacyCompat module is off (legacy_compat.enabled)';
        }
        if (! (bool) config('legacy_compat.import.enabled', false)) {
            $reasons[] = 'the import switch is off (legacy_compat.import.enabled)';
        }
        if ($sourceName === null || $sourceName === '') {
            $reasons[] = 'no source connection given (--source)';
        } elseif (! is_array(config('database.connections.'.$sourceName))) {
            $reasons[] = "source connection [{$sourceName}] is not configured";
        } elseif ($sourceName === $target->getName() || self::sameDatabase(DB::connection($sourceName)->getConfig(), $target->getConfig())) {
            $reasons[] = 'the source connection points at the target database';
        }
        if (trim((string) config('legacy_compat.import.source_timezone', '')) === '') {
            $reasons[] = 'the source time zone is not set (legacy_compat.import.source_timezone)';
        } elseif (! in_array((string) config('legacy_compat.import.source_timezone'), timezone_identifiers_list(), true)) {
            $reasons[] = 'the source time zone is not a valid zone name';
        }
        $reasons = [...$reasons, ...self::gateReasons()];
        if ($reasons !== []) {
            return $reasons;
        }

        return self::targetReasons($target);
    }

    /**
     * J60 + J62 gate shared by every command that writes migrated data (legacy:import, legacy:media-copy,
     * legacy:media-index): the target host checks and the owner sign-off. Empty = the gate is open.
     *
     * @return list<string>
     */
    public static function gateReasons(): array
    {
        $reasons = self::targetHostReasons();
        $signoff = self::signoffReason();
        if ($signoff !== null) {
            $reasons[] = $signoff;
        }

        return $reasons;
    }

    /**
     * J62 hard gate: the owner has signed off the hands-on demo, the feature parity matrix and the SEO parity report.
     * Only a strict true opens it.
     */
    public static function signoffReason(): ?string
    {
        if (config('legacy_compat.cutover.owner_signoff') !== true) {
            return 'the owner sign-off is missing (legacy_compat.cutover.owner_signoff, J62)';
        }

        return null;
    }

    /**
     * J60: every reason this install must not receive migrated data because of its own host. Fail closed: an
     * APP_URL without an http(s) scheme or a usable host is a reason, and the configured target host
     * (legacy_compat.import.target_host) must be set and equal the APP_URL host. The reasons never name a host.
     *
     * @return list<string>
     */
    public static function targetHostReasons(): array
    {
        $reasons = [];
        $forbidden = self::forbiddenTargetHostReason();
        if ($forbidden !== null) {
            $reasons[] = $forbidden;
        }

        $configured = self::normalizeHost((string) config('legacy_compat.import.target_host', ''));
        if ($configured === null) {
            $reasons[] = 'the target host is not set (legacy_compat.import.target_host)';
        } elseif ($configured !== self::appHost()) {
            $reasons[] = 'the target host does not equal the APP_URL host (legacy_compat.import.target_host)';
        }

        return $reasons;
    }

    /**
     * J60: the reason this install must not receive migrated data because of its own host, or null when the host may
     * receive it. The target host is the host of APP_URL: a fresh-install host (the built-in one, or one the owner
     * added) and every sub-domain of it are refused, so a fresh install never receives migrated data. The built-in
     * host is always refused, even when the configured list is emptied. An APP_URL whose host cannot be determined
     * is refused too (fail closed).
     *
     * The reason never names the host or the host list: it says what is wrong, not which host it is.
     */
    public static function forbiddenTargetHostReason(): ?string
    {
        $host = self::appHost();
        if ($host === null) {
            return 'the target APP_URL has no usable http(s) host (J60)';
        }

        $forbidden = array_merge(['juwri.com'], (array) config('legacy_compat.import.forbidden_target_hosts', []));
        foreach ($forbidden as $entry) {
            $entry = self::normalizeHost((string) $entry);
            if ($entry === null) {
                return 'a forbidden target host entry is invalid (J60)';
            }
            if ($host === $entry || str_ends_with($host, '.'.$entry)) {
                return 'the target APP_URL host is a fresh-install host that never receives migrated data (J60)';
            }
        }

        return null;
    }

    /** The normalised host of APP_URL, or null when APP_URL is not an http(s) URL with a host. */
    public static function appHost(): ?string
    {
        $url = trim((string) config('app.url', ''));
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        // The authority (between "//" and the first "/", "?" or "#") must not carry userinfo, a backslash or a
        // percent escape: browsers and parse_url disagree on such URLs, so they are refused outright.
        $authority = (string) preg_replace('#^https?://([^/?\#]*).*$#is', '$1', $url);
        if (preg_match('/[\\\\%@]/', $authority) === 1) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? self::normalizeHost($host) : null;
    }

    /** Test seam: a test binds this container key to force the "intl extension missing" branch. */
    public const NO_INTL_SEAM = 'legacy_compat.test_no_intl';

    private static function hasIntl(): bool
    {
        return function_exists('idn_to_ascii') && ! app()->bound(self::NO_INTL_SEAM);
    }

    /** Lower-cased, trailing dots stripped, IDN converted to ASCII; null when empty or not convertible. */
    public static function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '') {
            return null;
        }
        if (! self::hasIntl()) {
            // Without the intl extension a non-ASCII host cannot be compared safely: refused.
            return preg_match('/[^\x00-\x7F]/', $host) === 1 ? null : $host;
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false || $ascii === '' ? null : strtolower($ascii);
    }

    /** @return list<string> */
    public static function targetReasons(Connection $target): array
    {
        $schema = $target->getSchemaBuilder();
        if (! $schema->hasTable(RowLoader::SIDE_TABLE)) {
            return ['the target has no '.RowLoader::SIDE_TABLE.' table (run the migrations first)'];
        }
        $reasons = [];
        foreach ([...self::RECORDED_TABLES, ...self::OPTIONAL_RECORDED_TABLES] as $table) {
            if (! $schema->hasTable($table)) {
                if (! in_array($table, self::OPTIONAL_RECORDED_TABLES, true)) {
                    $reasons[] = "target table {$table} is missing";
                }

                continue;
            }
            $foreign = self::unrecordedRows($target, $table);
            if ($foreign > 0) {
                $reasons[] = "target table {$table} already holds {$foreign} row(s) not written by this importer";
            }
        }
        $imported = array_map('intval', $target->table(RowLoader::SIDE_TABLE)->where('target_table', 'stores')->pluck('target_id')->all());
        foreach (self::STORE_ROW_TABLES as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            // Ids compared in PHP: the side table keeps ids as text, the target column is an integer (pgsql).
            $foreign = count(array_diff(
                array_map('intval', $target->table($table)->whereNotNull('store_id')->distinct()->pluck('store_id')->all()),
                $imported,
            ));
            if ($foreign > 0) {
                $reasons[] = "target table {$table} already holds {$foreign} row(s) of stores not written by this importer";
            }
        }

        return $reasons;
    }

    /**
     * Rows of $table whose id has no side-table record. Compared by id, not by count: a deleted imported row plus a
     * new row would leave the counts equal. Ids are compared in PHP (text in the side table, integer here), a chunk
     * at a time.
     */
    private static function unrecordedRows(Connection $target, string $table): int
    {
        if (! $target->table($table)->exists()) {
            return 0;
        }
        $recorded = array_flip(array_map('strval', $target->table(RowLoader::SIDE_TABLE)->where('target_table', $table)->pluck('target_id')->all()));
        $foreign = 0;
        $last = 0;
        while (true) {
            $ids = $target->table($table)->where('id', '>', $last)->orderBy('id')->limit(5000)->pluck('id')->all();
            if ($ids === []) {
                return $foreign;
            }
            foreach ($ids as $id) {
                if (! isset($recorded[(string) $id])) {
                    $foreign++;
                }
            }
            $last = (int) end($ids);
        }
    }

    /**
     * Same database? Compared on the RESOLVED configs (a `url` is already parsed into host/port/database), with
     * localhost / ::1 / 127.0.0.1 treated as one host and the driver's default port filled in. When in doubt the
     * answer is "same" (the guard refuses). The table prefix is deliberately NOT compared: the old platform's tables
     * under another prefix in the target's own database are still the target database (refused).
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    public static function sameDatabase(array $source, array $target): bool
    {
        $driver = strtolower((string) ($source['driver'] ?? ''));
        if ($driver === 'mariadb') {
            $driver = 'mysql';
        }
        $targetDriver = strtolower((string) ($target['driver'] ?? ''));
        if ($targetDriver === 'mariadb') {
            $targetDriver = 'mysql';
        }
        if ($driver !== $targetDriver) {
            return false;
        }
        if ($driver === 'sqlite') {
            $a = self::sqlitePath($source['database'] ?? '');
            $b = self::sqlitePath($target['database'] ?? '');

            // Two ':memory:' connections are separate databases; a shared file is the same one.
            return $a !== ':memory:' && $a === $b;
        }

        return self::host($source) === self::host($target)
            && self::port($source, $driver) === self::port($target, $driver)
            && strtolower((string) ($source['database'] ?? '')) === strtolower((string) ($target['database'] ?? ''));
    }

    /** @param array<string, mixed> $config */
    private static function host(array $config): string
    {
        $host = $config['write']['host'] ?? $config['host'] ?? '';
        if (is_array($host)) {
            $host = $host[0] ?? '';
        }
        $host = strtolower(trim((string) $host, ' []'));
        if (! empty($config['unix_socket'])) {
            return 'socket:'.(string) $config['unix_socket'];
        }

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true) ? '127.0.0.1' : $host;
    }

    /** @param array<string, mixed> $config */
    private static function port(array $config, string $driver): string
    {
        $port = (string) ($config['write']['port'] ?? $config['port'] ?? '');
        if ($port !== '') {
            return $port;
        }

        return match ($driver) {
            'mysql' => '3306',
            'pgsql' => '5432',
            'sqlsrv' => '1433',
            default => '',
        };
    }

    private static function sqlitePath(mixed $database): string
    {
        $path = (string) $database;
        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory')) {
            return ':memory:';
        }
        $real = realpath($path);

        return $real === false ? $path : $real;
    }
}
