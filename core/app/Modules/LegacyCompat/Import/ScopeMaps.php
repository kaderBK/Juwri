<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Carbon\CarbonImmutable;

/**
 * Owner decision J59g/J59h (scope added after the production schema review): live hotel business (rate plans, room
 * rates, open stays, future bookings), future and open reservations and catering requests with their paid deposits, push
 * subscriptions (the install keeps the old origin, J59b), storefront API keys, and store page views (visitor IPs hashed).
 *
 * Every row is a row of the store being imported (J29-1: account and store from the loader); every reference must name
 * a row of the same store, else NULL / skipped and reported. Tokens that are in links customers and guests hold
 * (booking, stay, reservation and catering tokens, booking codes) are kept verbatim; a stay token stays the guest-folio
 * credential and charge-to-room still needs the room's own token (J57). Storefront keys are stored hashed only.
 *
 * Past and closed business (past bookings, closed stays, past or closed reservations and catering requests) is not
 * migrated: skipped and counted per reason. A paid deposit is migrated only when it reconciles with its payment
 * ({@see DepositChecks}); the store is blocked otherwise.
 */
final class ScopeMaps
{
    private const TIMESTAMPS = ['created_at' => 'created_at', 'updated_at' => 'updated_at'];

    /** Booking statuses that still hold a room. */
    public const OPEN_BOOKINGS = ['pending', 'confirmed', 'checked_in'];

    /** Reservation / catering statuses of business still to happen. */
    public const OPEN_RESERVATIONS = ['pending', 'confirmed'];

    public const OPEN_CATERING = ['requested', 'quoted', 'accepted'];

    /** Deposit states whose money is still held for the customer (or owed back to them). */
    public const HELD_DEPOSITS = ['paid', 'refund_due'];

    /** Mark on reservations / catering requests: a held deposit, its holding ledger legs booked at import. */
    public const DEPOSIT_HELD = 'legacy_deposit_held';

    /**
     * Mark on an imported payment that is still LIVE money (a deposit payment of a migrated reservation / catering
     * request): unlike the rest of the imported billing history it may still be refunded here, so the history guard
     * lets its refund bookkeeping through ({@see \Juwri\Modules\LegacyCompat\Support\LegacyHistoryGuard}).
     */
    public const LIVE_PAYMENT = 'legacy_live_payment';

    /** Mark on hotel stays: the open folio balance carried over from the source (minor units). */
    public const FOLIO_OPENED = 'legacy_folio_opening';

    /** @return list<TableMap> right after the store's tables (rooms) */
    public static function afterTables(): array
    {
        return [self::ratePlans(), self::roomRates(), self::hotelStays()];
    }

    /** @return list<TableMap> right after the store's customers */
    public static function afterCustomers(): array
    {
        return [self::hotelBookings(), self::hotelBookingNights()];
    }

    /** @return list<TableMap> after the store's orders */
    public static function afterOrders(): array
    {
        return [
            self::reservations(), self::cateringRequests(), self::customerPushSubscriptions(), self::pushSubscriptions(),
            self::storefrontTokens(), self::storeViews(), self::storeViewDaily(),
        ];
    }

    /** Hotel rate plans: a percent modifier stays a rate, a fixed one becomes minor units of the store currency. */
    public static function ratePlans(): TableMap
    {
        return new TableMap(
            source: 'rate_plans', target: 'rate_plans', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['name' => 'name', 'parent_id' => 'parent_id', 'modifier_type' => 'modifier_type', 'cancel_before_hours' => 'cancel_before_hours'],
            dates: self::TIMESTAMPS, bools: ['refundable' => 'refundable', 'active' => 'active'],
            selfParent: 'parent_id', currency: true, uses: ['modifier_value'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $value = $row['modifier_value'] ?? null;
                $out['modifier_value'] = null;
                $out['modifier_amount_minor'] = null;
                if ($value === null || $value === '') {
                    return $out;
                }
                if (! is_numeric($value)) {
                    return 'rate plan modifier not numeric';
                }
                if (($out['modifier_type'] ?? null) === 'fixed') {
                    $out['modifier_amount_minor'] = $ctx->minor($value);
                } else {
                    $out['modifier_value'] = (string) $value;
                }

                return $out;
            },
        );
    }

    public static function roomRates(): TableMap
    {
        return new TableMap(
            source: 'room_rates', target: 'room_rates', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['room_type_id' => 'room_type_id', 'rate_plan_id' => 'rate_plan_id', 'date' => 'date'],
            dates: self::TIMESTAMPS, money: ['price_minor' => 'price'], currency: true,
            requiredRefs: ['room_type_id' => 'room_types'], refs: ['rate_plan_id' => 'rate_plans'],
            transform: static fn (array $out): array|string => isset($out['price_minor']) ? $out : 'room rate price not numeric',
        );
    }

    /**
     * Open stays only (a guest in a room now). The stay token is kept (guest-folio link). The source folio is a ledger
     * account of the source (not imported, J58c): its open balance is carried over as one opening charge on the stay's
     * folio here ({@see LegacyLedgerOpenings::folios()}).
     */
    public static function hotelStays(): TableMap
    {
        return new TableMap(
            source: 'hotel_stays', target: 'hotel_stays', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['branch_id' => 'branch_id', 'table_id' => 'table_id', 'guest_name' => 'guest_name', 'guest_phone' => 'guest_phone', 'status' => 'status', 'token' => 'token'],
            dates: ['checked_in_at' => 'checked_in_at', 'checked_out_at' => 'checked_out_at'] + self::TIMESTAMPS,
            requiredRefs: ['table_id' => 'store_tables'], refs: ['branch_id' => 'branches'],
            uses: ['folio_account_id'],
            transform: static function (array $out): array|string {
                if (($out['status'] ?? null) !== 'checked_in' || ($out['checked_out_at'] ?? null) !== null) {
                    return 'closed stay: not migrated (J59g open stays only)';
                }
                $out['folio_account_id'] = null; // opened here by the import (see afterLoad)
                $out['guest_name'] = (string) ($out['guest_name'] ?? '');

                return $out;
            },
            afterLoad: static fn (ImportContext $ctx, array $ids) => (new LegacyLedgerOpenings($ctx))->folios($ids),
        );
    }

    /** Future bookings that still hold a room (code and token verbatim: they are in the guest's links). */
    public static function hotelBookings(): TableMap
    {
        $same = ['branch_id', 'code', 'token', 'guest_name', 'guest_phone', 'customer_id', 'room_type_id', 'room_id', 'rate_plan_id', 'check_in', 'check_out', 'adults', 'children', 'status', 'source', 'hotel_stay_id', 'notes'];

        return new TableMap(
            source: 'hotel_bookings', target: 'hotel_bookings', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: array_combine($same, $same), dates: self::TIMESTAMPS,
            requiredRefs: ['room_type_id' => 'room_types'],
            refs: ['branch_id' => 'branches', 'customer_id' => 'customers', 'room_id' => 'store_tables', 'rate_plan_id' => 'rate_plans', 'hotel_stay_id' => 'hotel_stays'],
            uses: ['quoted_total', 'currency'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                if (! in_array($out['status'] ?? null, self::OPEN_BOOKINGS, true) || (string) ($out['check_out'] ?? '') < self::today($ctx)) {
                    return 'past or closed booking: not migrated (J59g future bookings only)';
                }
                $money = self::ownMoney($row['quoted_total'] ?? null, $row['currency'] ?? null, $ctx);
                if (is_string($money)) {
                    return 'booking '.$money;
                }
                [$out['quoted_total_minor'], $out['currency']] = $money;

                return $out;
            },
        );
    }

    /** The nights a kept booking holds (availability): the room must be a room of the same store. */
    public static function hotelBookingNights(): TableMap
    {
        return new TableMap(
            source: 'hotel_booking_nights', target: 'hotel_booking_nights', scope: TableMap::CHILD,
            parentColumn: 'booking_id', parentTarget: 'hotel_bookings',
            columns: ['room_id' => 'room_id', 'night' => 'night'], dates: self::TIMESTAMPS,
            requiredRefs: ['room_id' => 'store_tables'],
        );
    }

    /**
     * Reservations still to happen, and any reservation whose deposit is still held or owed back. The token and the
     * booking-partner id are kept verbatim. A held deposit's payment moves to this store (refunds go through its own
     * gateway) and the holding ledger legs are booked as the deposit payment booked them at the source.
     */
    public static function reservations(): TableMap
    {
        $same = ['branch_id', 'name', 'phone', 'email', 'party_size', 'deposit_status', 'deposit_transaction_id', 'table_number', 'status', 'notes', 'token', 'rwg_booking_id', 'source', 'table_id'];

        return new TableMap(
            source: 'reservations', target: 'reservations', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: array_combine($same, $same), dates: ['reserved_at' => 'reserved_at'] + self::TIMESTAMPS,
            refs: ['branch_id' => 'branches', 'table_id' => 'store_tables'],
            uses: ['deposit_amount', 'currency'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                if (! self::keepReservation($row, $ctx)) {
                    return 'past or closed reservation: not migrated (J59g future / open only)';
                }
                $money = self::ownMoney($row['deposit_amount'] ?? 0, $row['currency'] ?? null, $ctx);
                if (is_string($money)) {
                    return 'reservation deposit '.$money;
                }
                [$out['deposit_amount_minor'], $out['currency']] = $money;
                $out['deposit_amount_minor'] ??= 0;

                return self::depositPayment($out, 'reservations', $ctx);
            },
            afterLoad: static fn (ImportContext $ctx, array $ids) => (new LegacyLedgerOpenings($ctx))->deposits('reservations', $ids),
        );
    }

    /** Catering requests still to happen (or with a deposit owed back); quote and deposit in the request's currency. */
    public static function cateringRequests(): TableMap
    {
        $same = ['branch_id', 'name', 'phone', 'email', 'headcount', 'message', 'items', 'status', 'token', 'quote_note', 'order_id', 'deposit_status', 'deposit_transaction_id'];

        return new TableMap(
            source: 'catering_requests', target: 'catering_requests', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: array_combine($same, $same), dates: ['event_at' => 'event_at'] + self::TIMESTAMPS,
            refs: ['branch_id' => 'branches', 'order_id' => 'orders'],
            uses: ['quote_total', 'deposit_amount', 'currency'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                if (! self::keepCatering($row, $ctx)) {
                    return 'past or closed catering request: not migrated (J59g future / open only)';
                }
                foreach (['quote_total' => 'quote_total_minor', 'deposit_amount' => 'deposit_amount_minor'] as $column => $target) {
                    $money = self::ownMoney($row[$column] ?? null, $row['currency'] ?? null, $ctx);
                    if (is_string($money)) {
                        return 'catering '.$column.' '.$money;
                    }
                    [$out[$target], $out['currency']] = $money;
                }

                return self::depositPayment($out, 'catering_requests', $ctx);
            },
            afterLoad: static fn (ImportContext $ctx, array $ids) => (new LegacyLedgerOpenings($ctx))->deposits('catering_requests', $ids),
        );
    }

    /** Order-status push of a customer's browser (same origin, J59b): the order must be an order of this store. */
    public static function customerPushSubscriptions(): TableMap
    {
        return new TableMap(
            source: 'customer_push_subscriptions', target: 'customer_push_subscriptions', scope: TableMap::STORE,
            storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['order_id' => 'order_id', 'endpoint' => 'endpoint', 'p256dh' => 'p256dh', 'auth' => 'auth', 'content_encoding' => 'content_encoding'],
            dates: ['failed_at' => 'failed_at'] + self::TIMESTAMPS,
            requiredRefs: ['order_id' => 'orders'], ignore: ['endpoint_hash'],
            transform: static fn (array $out): array|string => self::endpoint($out),
        );
    }

    /** Owner / staff device push of the store: a staff member of the same store; an owner device only the store owner's. */
    public static function pushSubscriptions(): TableMap
    {
        $same = ['staff_id', 'platform', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'user_id', 'device_name', 'app_version', 'os_version'];

        return new TableMap(
            source: 'push_subscriptions', target: 'push_subscriptions', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: array_combine($same, $same),
            dates: ['failed_at' => 'failed_at', 'last_seen_at' => 'last_seen_at'] + self::TIMESTAMPS,
            bools: ['notify_new_order' => 'notify_new_order'],
            refs: ['staff_id' => 'staff_members'], ignore: ['endpoint_hash'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $user = $out['user_id'] ?? null;
                if ($user !== null && (int) $user > 0) {
                    $owner = (int) $ctx->target->table('stores')->where('id', (int) $ctx->storeId)->value('user_id');
                    if ((int) $user !== $owner) {
                        $out['user_id'] = null;
                        $ctx->report->conflict('push_subscriptions.user_id: not the store owner, set NULL', 'push_subscriptions#'.(int) $out['id']);
                    }
                } else {
                    $out['user_id'] = null;
                }

                return self::endpoint($out);
            },
        );
    }

    /**
     * Headless storefront API keys. This install stores only the sha256 of a key (it compares the hash of what the
     * caller sends): a source value that is already such a hash is kept; anything else (a plaintext key) is hashed here
     * and never stored in plaintext. Integrations keep working with the key they hold.
     */
    public static function storefrontTokens(): TableMap
    {
        return new TableMap(
            source: 'storefront_tokens', target: 'storefront_tokens', scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['token' => 'token', 'label' => 'label', 'scopes' => 'scopes', 'allowed_origins' => 'allowed_origins'],
            dates: ['last_used_at' => 'last_used_at'] + self::TIMESTAMPS,
            bools: ['browser' => 'browser', 'active' => 'active'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $token = trim((string) ($out['token'] ?? ''));
                if ($token === '') {
                    return 'storefront key empty';
                }
                if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
                    $token = hash('sha256', $token);
                    $ctx->report->count('storefront_tokens_hashed_at_import');
                }
                $out['token'] = $token;

                return $out;
            },
        );
    }

    /**
     * Store page views (J59h): the visitor IP is hashed on import ({@see self::hashIp()}): this install stores no hash
     * scheme of its own for views, so a keyed sha256 with an install secret is used; one visitor keeps one hash, so
     * repeat-visitor counts still work.
     */
    public static function storeViews(): TableMap
    {
        return new TableMap(
            source: 'post_views', target: 'store_page_views', scope: TableMap::STORE, storeColumns: ['post_id'],
            columns: ['device' => 'device', 'lang' => 'lang', 'source' => 'source', 'dwell_ms' => 'dwell_ms', 'exit_section' => 'exit_section'],
            dates: ['date' => 'date'], uses: ['ip'],
            // The side-table checksum never carries the plain IP (it could be brute-forced back from the other columns).
            checksumRow: static fn (array $row): array => ['ip' => self::hashIp($row['ip'] ?? null)] + $row,
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                $out['ip'] = self::hashIp($row['ip'] ?? null);
                if ($out['ip'] !== null) {
                    $ctx->report->count('store_page_views_ip_hashed');
                }

                return $out;
            },
        );
    }

    /** Daily view rollups (owner statistics). */
    public static function storeViewDaily(): TableMap
    {
        return new TableMap(
            source: 'post_view_daily', target: 'store_page_view_daily', scope: TableMap::STORE, storeColumns: ['post_id'],
            columns: ['date' => 'date', 'device' => 'device', 'lang' => 'lang', 'scans' => 'scans'],
        );
    }

    /** Keyed sha256 of a visitor IP (install secret: this install's app key). Empty stays NULL. */
    public static function hashIp(mixed $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, 'store-view-ip|'.(string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>|string
     */
    private static function endpoint(array $out): array|string
    {
        $endpoint = trim((string) ($out['endpoint'] ?? ''));
        if ($endpoint === '') {
            return 'push endpoint empty';
        }
        // The same hash this install computes for a new subscription (dedupe key).
        $out['endpoint_hash'] = hash('sha256', $endpoint);

        return $out;
    }

    /** Today's date at the source (dates of bookings carry no zone). */
    public static function today(ImportContext $ctx): string
    {
        return CarbonImmutable::now($ctx->sourceTimezone)->format('Y-m-d');
    }

    /**
     * A reservation still to happen (open status, not in the past), or one whose deposit is still owed back.
     *
     * @param  array<string, mixed>  $row  source row
     */
    public static function keepReservation(array $row, ImportContext $ctx): bool
    {
        if (($row['deposit_status'] ?? null) === 'refund_due') {
            return true;
        }
        $at = $ctx->utc($row['reserved_at'] ?? null, $ctx->source->isInstant('reservations', 'reserved_at'));

        return in_array($row['status'] ?? null, self::OPEN_RESERVATIONS, true) && $at !== null && $at >= now()->utc()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row source row */
    public static function keepCatering(array $row, ImportContext $ctx): bool
    {
        if (($row['deposit_status'] ?? null) === 'refund_due') {
            return true;
        }
        $at = $ctx->utc($row['event_at'] ?? null, $ctx->source->isInstant('catering_requests', 'event_at'));

        return in_array($row['status'] ?? null, self::OPEN_CATERING, true) && $at !== null && $at >= now()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * An amount in its row's own currency (or the store's): [minor or null, currency code], or the reason it fails.
     *
     * @return array{0: int|null, 1: string}|string
     */
    public static function ownMoney(mixed $amount, mixed $currency, ImportContext $ctx): array|string
    {
        $code = strtoupper(trim((string) $currency));
        $code = $code === '' ? (string) $ctx->currency : $code;
        $exponent = $ctx->exponentOf($code);
        if ($exponent === null) {
            return 'currency unknown to this install';
        }
        if ($amount === null || $amount === '') {
            return [null, $code];
        }
        $minor = $ctx->minor($amount, $exponent);

        return $minor === null ? 'amount not numeric' : [$minor, $code];
    }

    /**
     * The deposit payment of a reservation / catering request: kept only when it is an imported payment of this store's
     * owner (checked before the store was written, {@see DepositChecks}); a payment that is not (a pending deposit
     * whose payment never completed) is dropped to NULL.
     *
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>
     */
    private static function depositPayment(array $out, string $table, ImportContext $ctx): array
    {
        $payment = $out['deposit_transaction_id'] ?? null;
        if ($payment === null || (int) $payment <= 0) {
            $out['deposit_transaction_id'] = null;

            return $out;
        }
        $owned = $ctx->resolvesPlatform('transaction', (int) $payment)
            && (int) $ctx->target->table('transaction')->where('id', (int) $payment)->value('account_id') === (int) $ctx->accountId;
        if (! $owned) {
            $out['deposit_transaction_id'] = null;
            $ctx->report->conflict($table.'.deposit_transaction_id: payment not imported for this store owner, set NULL', $table.'#'.(int) $out['id']);

            return $out;
        }
        // The payment is the store's (it is completed or refunded through the store's own gateway) and live money.
        $ctx->target->table('transaction')->where('id', (int) $payment)->where('account_id', (int) $ctx->accountId)
            ->whereNull('store_id')->update(['store_id' => (int) $ctx->storeId]);
        if (! isset($ctx->lookups['live_payment:'.(int) $payment])) {
            $ctx->lookups['live_payment:'.(int) $payment] = (int) $payment;
            $ctx->mark('transaction', (int) $payment, self::LIVE_PAYMENT, ['for' => $table.'#'.(int) $out['id']]);
        }

        return $out;
    }
}
