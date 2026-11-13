<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * Marketplace (J58d): shoppers, their wallet ledger (customer money), addresses, their links to stores (store customer,
 * favourites, follows) and the marketplace page sections. The targets are the Enterprise marketplace tables: an install
 * without them imports none of this and says so in the report (nothing is guessed into other tables).
 *
 * Tenancy (J29): a shopper is a platform identity of one marketplace (`reseller_id` 0 = the platform's), so shoppers,
 * their wallet ledger and addresses are platform rows; a shopper of a reseller is imported only when that same reseller
 * is already on this install (never moved into the platform's marketplace). The store links are rows of the store
 * being imported (account and store from the loader); their customer must be a customer of the same store.
 *
 * Money: wallet amounts and balance snapshots in minor units of each row's own currency ({@see LegacyMoney}); a shopper
 * whose ledger does not add up to its balance snapshots is blocked as a whole ({@see ShopperWallets}).
 *
 * Not imported: push subscriptions (origin-bound, open owner question R7), dispatch offers (live delivery state of
 * drivers, J58c), and the app-store media / reviews (they belong to app listings, J58c); the directory listing tables
 * are reported as unmapped until their scope is decided.
 */
final class MarketplaceMaps
{
    public const SHOPPERS = 'shoppers';

    public const WALLET = 'shopper_wallet_ledger';

    public const COUNTRIES = 'countries';

    /** Source store column naming the marketplace (reseller) a store is listed in. */
    public const STORE_RESELLER = 'mk_reseller_id';

    /** Store links of a shopper: source table => target table. */
    public const STORE_LINKS = ['shopper_customer' => 'shopper_customers', 'shopper_favorites' => 'shopper_favorites', 'shopper_follows' => 'shopper_follows'];

    private const TIMESTAMPS = ['created_at' => 'created_at', 'updated_at' => 'updated_at'];

    /**
     * Platform loaders after the shoppers (the shoppers and their wallets load one shopper at a time, {@see ShopperWallets}).
     *
     * @return list<TableMap>
     */
    public static function platform(): array
    {
        return [self::addresses(), self::sections()];
    }

    /**
     * Per-store loaders, after the store's customers.
     *
     * @return list<TableMap>
     */
    public static function perStore(): array
    {
        $maps = [];
        foreach (self::STORE_LINKS as $source => $target) {
            $maps[] = self::storeLink($source, $target);
        }

        return $maps;
    }

    /**
     * Shoppers (ids kept: wallet rows, addresses and store links name them). The country is matched by its code (the
     * source's country ids are not this install's); a shopper whose country or reseller is not on this install is not
     * imported (reported). Sessions are not migrated (Q8): the remember token is dropped.
     */
    public static function shoppers(): TableMap
    {
        return new TableMap(
            source: self::SHOPPERS, target: 'shoppers', scope: TableMap::PLATFORM,
            columns: ['phone' => 'phone', 'name' => 'name', 'email' => 'email', 'locale' => 'locale'],
            dates: ['verified_at' => 'verified_at'] + self::TIMESTAMPS,
            ignore: ['remember_token'],
            uses: ['country_id', 'reseller_id'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $country = self::country((int) ($row['country_id'] ?? 0), $ctx);
                if ($country === null) {
                    return 'shopper country not on this install (matched by country code)';
                }
                $reseller = (int) ($row['reseller_id'] ?? 0);
                if ($reseller > 0 && ! self::resellerOnInstall($reseller, $ctx)) {
                    return 'shopper of a reseller marketplace whose reseller is not on this install (import it with the reseller)';
                }
                $out['country_id'] = $country;
                $out['reseller_id'] = max(0, $reseller);
                $out['phone'] = (string) ($out['phone'] ?? '');

                return $out;
            },
        );
    }

    /**
     * Wallet ledger rows of one shopper (ids kept): amounts and balance snapshots in minor units of the row's own
     * currency. Written only together with the shopper, after the ledger checks ({@see ShopperWallets}).
     */
    public static function walletEntries(): TableMap
    {
        return new TableMap(
            source: self::WALLET, target: 'shopper_wallet_ledger_entries', scope: TableMap::PLATFORM,
            columns: [
                'shopper_id' => 'shopper_id', 'type' => 'type', 'ref_type' => 'ref_type', 'ref_id' => 'ref_id',
                'idempotency_key' => 'idempotency_key', 'reason' => 'reason', 'created_by' => 'created_by', 'meta' => 'meta',
            ],
            dates: ['created_at' => 'created_at'],
            requiredRefs: ['shopper_id' => 'shoppers'],
            // An admin who made an adjustment: kept when that user is on this install.
            refs: ['created_by' => 'users'],
            uses: ['amount', 'currency', 'balance_after'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                $exponent = $ctx->exponentOf($currency);
                if ($exponent === null) {
                    return 'wallet currency unknown to this install';
                }
                $amount = $ctx->minor($row['amount'] ?? null, $exponent);
                if ($amount === null) {
                    return 'wallet amount not numeric';
                }
                $after = $row['balance_after'] ?? null;
                $out['balance_after_minor'] = $after === null || $after === '' ? null : $ctx->minor($after, $exponent);
                if ($out['balance_after_minor'] === null && $after !== null && $after !== '') {
                    return 'wallet balance snapshot not numeric';
                }
                $out['amount_minor'] = $amount;
                $out['currency'] = $currency;

                // ref_type / ref_id name the order or payment the movement was for: kept verbatim (history).
                return $out;
            },
        );
    }

    /** Saved addresses of a shopper. Cities are not imported yet: a city id is dropped (counted). */
    public static function addresses(): TableMap
    {
        return new TableMap(
            source: 'shopper_addresses', target: 'shopper_addresses', scope: TableMap::PLATFORM,
            columns: ['shopper_id' => 'shopper_id', 'label' => 'label', 'address' => 'address', 'lat' => 'lat', 'lng' => 'lng', 'notes' => 'notes'],
            dates: self::TIMESTAMPS,
            bools: ['is_default' => 'is_default'],
            requiredRefs: ['shopper_id' => 'shoppers'],
            uses: ['city_id'],
            transform: static function (array $out, array $row, ImportContext $ctx): array {
                if ((int) ($row['city_id'] ?? 0) > 0) {
                    $ctx->report->count('shopper_addresses_city_dropped');
                }
                $out['city_id'] = null;
                $out['label'] = (string) ($out['label'] ?? '');
                $out['address'] = (string) ($out['address'] ?? '');

                return $out;
            },
        );
    }

    /**
     * Marketplace page sections: the platform's (reseller 0), or a reseller's when that reseller is on this install.
     * A section limited to one city is not imported while cities are not (reported).
     */
    public static function sections(): TableMap
    {
        return new TableMap(
            source: 'mk_sections', target: 'marketplace_sections', scope: TableMap::PLATFORM,
            columns: ['type' => 'type', 'title' => 'title', 'config' => 'config', 'position' => 'position'],
            dates: self::TIMESTAMPS,
            bools: ['active' => 'active'],
            uses: ['reseller_id', 'city_id'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $reseller = (int) ($row['reseller_id'] ?? 0);
                if ($reseller > 0 && ! self::resellerOnInstall($reseller, $ctx)) {
                    return "section of a reseller's marketplace whose reseller is not on this install";
                }
                if ((int) ($row['city_id'] ?? 0) > 0) {
                    return 'section of one city (cities are not imported yet)';
                }
                $out['reseller_id'] = max(0, $reseller);
                $out['city_id'] = null;
                $out['position'] = max(0, (int) ($out['position'] ?? 0));

                return $out;
            },
        );
    }

    /** A shopper's link to a store (store customer, favourite, follow): a row of the store being imported. */
    public static function storeLink(string $source, string $target): TableMap
    {
        $customer = $target === 'shopper_customers';

        return new TableMap(
            source: $source, target: $target, scope: TableMap::STORE, storeColumns: LegacyTableMaps::STORE_KEYS,
            columns: ['shopper_id' => 'shopper_id'] + ($customer ? ['customer_id' => 'customer_id'] : []),
            dates: self::TIMESTAMPS,
            // The shopper must be imported; the store customer must be one of THIS store's customers (J29).
            requiredRefs: ['shopper_id' => 'shoppers'] + ($customer ? ['customer_id' => 'customers'] : []),
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                // A shopper of one marketplace never links to a store of another (J29).
                $shopper = self::shopperReseller((int) $out['shopper_id'], $ctx);
                $store = self::storeReseller((int) $ctx->storeId, $ctx);
                if ($shopper !== $store) {
                    return 'shopper of another marketplace than the store (reseller#'.$shopper.' vs reseller#'.$store.')';
                }

                return $out;
            },
        );
    }

    /** The source marketplace (reseller id, 0 = the platform's) of a source shopper. */
    public static function shopperReseller(int $shopperId, ImportContext $ctx): int
    {
        $key = 'shopper_reseller:'.$shopperId;
        if (! array_key_exists($key, $ctx->lookups)) {
            $ctx->lookups[$key] = max(0, (int) ($ctx->source->find(self::SHOPPERS, $shopperId)['reseller_id'] ?? 0));
        }

        return (int) $ctx->lookups[$key];
    }

    /**
     * The source marketplace of a source store: its own marketplace reseller column when the source has one, else its
     * owner's reseller; 0 = the platform's.
     */
    public static function storeReseller(int $storeId, ImportContext $ctx): int
    {
        $key = 'store_reseller:'.$storeId;
        if (! array_key_exists($key, $ctx->lookups)) {
            $source = $ctx->source;
            $store = $source->find(LegacyTableMaps::STORES, $storeId) ?? [];
            if (array_key_exists(self::STORE_RESELLER, $store)) {
                $reseller = (int) ($store[self::STORE_RESELLER] ?? 0);
            } else {
                $owner = $source->find(LegacyTableMaps::USERS, (int) ($store[LegacyTableMaps::STORE_OWNER] ?? 0)) ?? [];
                $reseller = (int) ($owner['reseller_id'] ?? 0);
            }
            $ctx->lookups[$key] = max(0, $reseller);
        }

        return (int) $ctx->lookups[$key];
    }

    /** This install's country id for a source country id, matched by the country code (memoised per run). */
    public static function country(int $sourceId, ImportContext $ctx): ?int
    {
        $key = 'country:'.$sourceId;
        if (array_key_exists($key, $ctx->lookups)) {
            return $ctx->lookups[$key];
        }
        $id = null;
        $row = $sourceId > 0 && $ctx->source->has(self::COUNTRIES) ? $ctx->source->find(self::COUNTRIES, $sourceId) : null;
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        if ($code !== '') {
            $found = $ctx->target->table('countries')->whereRaw('upper(code) = ?', [$code])->orderBy('id')->value('id');
            $id = $found === null ? null : (int) $found;
        }

        return $ctx->lookups[$key] = $id;
    }

    /**
     * Is source reseller $id on this install as the SAME reseller (imported with its id, side-table record)? A reseller
     * created here that happens to have that id is another tenant (J29).
     */
    public static function resellerOnInstall(int $id, ImportContext $ctx): bool
    {
        $key = 'reseller:'.$id;
        if (! array_key_exists($key, $ctx->lookups)) {
            $imported = $ctx->target->table(RowLoader::SIDE_TABLE)->where('source_table', LegacyTableMaps::RESELLERS)
                ->where('source_id', (string) $id)->where('target_table', 'resellers')->where('target_id', (string) $id)->exists()
                && $ctx->target->table('resellers')->where('id', $id)->exists();
            $ctx->lookups[$key] = $imported ? $id : null;
        }

        return $ctx->lookups[$key] !== null;
    }
}
