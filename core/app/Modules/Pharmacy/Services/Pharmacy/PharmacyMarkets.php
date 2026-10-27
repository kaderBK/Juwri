<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Services\Pharmacy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;

/**
 * P1-23 — the Acceptable Use Policy 3.1 rules for pharmacy stores, per country (config/pharmacy.php):
 *
 *  - (a)/(b) a pharmacy store is shown and sells online only in an ENABLED country, with licence documents the
 *    platform reviewed and approved, and only until that licence expires (an expired licence unpublishes the store
 *    the next day, without a job — every check reads the date);
 *  - per-market quantity cap per medicine and order, which the store can only lower;
 *  - (d) the per-market promotion block (DECISIONS 2026-10-04; on for SA by default): no coupon on an order holding
 *    medicines, and on every medicine line no scheduled / sale / happy-hour price, no combo or bundle holding a
 *    medicine, no loyalty points earned or redeemed. Non-medicine lines (cosmetics, baby care) are unaffected.
 *
 * Other store types are never restricted. Bound as the Ordering module's {@see SalesRestrictions} and the Menu
 * module's {@see PromotionRules} (one instance).
 */
final class PharmacyMarkets implements PromotionRules, SalesRestrictions
{
    /** Per-store facts memo life: a long-running worker re-reads a store's type and market at least this often. */
    private const MEMO_SECONDS = 60;

    /** @var array<int, array{at: int, pharmacy: bool, country: ?string}> */
    private array $facts = [];

    public const STORE_TYPE = 'pharmacy';

    /** Store setting: the store's own, stricter, per-order cap for one medicine (0/empty = the market's). */
    public const OPTION_QUANTITY_CAP = 'pharmacy_quantity_cap';

    public static function isPharmacy(Store $store): bool
    {
        return (string) $store->store_type === self::STORE_TYPE;
    }

    /**
     * The store's pharmacy market: the country of its newest licence upload, else its fiscal country, else the
     * owner's country. Null when none is known.
     */
    public function country(Store $store): ?string
    {
        $candidates = [
            PharmacyLicence::latestFor((int) $store->id)?->country,
            (string) store_options((int) $store->id, 'fiscal_country', ''),
            (string) DB::table('users')->where('id', $store->user_id)->value('country_code'),
        ];
        foreach ($candidates as $c) {
            $c = strtoupper(trim((string) $c));
            if (preg_match('/^[A-Z]{2}$/', $c)) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function enabledCountries(): array
    {
        return array_values((array) config('pharmacy.enabled_countries', []));
    }

    public static function countryEnabled(?string $country): bool
    {
        return $country !== null && in_array($country, self::enabledCountries(), true);
    }

    /**
     * The market's rules: the configured override (pharmacy.markets) over the built-in one (pharmacy.default_markets,
     * SA blocks medicine promotions) over the defaults. `promotions` is the legacy inverse of
     * `block_medicine_promotions` (true = allowed), still accepted in an override.
     *
     * @return array{quantity_cap: int, block_medicine_promotions: bool, promotions: bool}
     */
    public static function marketRules(?string $country): array
    {
        $key = $country ?? '';
        $override = (array) (((array) config('pharmacy.markets', []))[$key] ?? []);
        $builtIn = (array) (((array) config('pharmacy.default_markets', []))[$key] ?? []);

        $block = null;
        foreach ([$override, $builtIn] as $market) {
            if (array_key_exists('block_medicine_promotions', $market)) {
                $block = (bool) $market['block_medicine_promotions'];
                break;
            }
            if (array_key_exists('promotions', $market)) {
                $block = ! (bool) $market['promotions'];
                break;
            }
        }
        $block ??= (bool) config('pharmacy.block_medicine_promotions', false);

        return [
            'quantity_cap' => max(1, (int) ($override['quantity_cap'] ?? $builtIn['quantity_cap'] ?? config('pharmacy.quantity_cap', 2))),
            'block_medicine_promotions' => $block,
            'promotions' => ! $block,
        ];
    }

    /** Whether this store is a pharmacy whose market blocks promotions on medicines. */
    public function medicinePromotionsBlocked(Store $store): bool
    {
        return self::isPharmacy($store) && self::marketRules($this->country($store))['block_medicine_promotions'];
    }

    /** A medicine line: items.is_medicine, true unless the owner marked the product "not a medicine". */
    public static function isMedicine(object $item): bool
    {
        return (bool) ($item->is_medicine ?? true);
    }

    public function blocksPromotions(Menu $item): bool
    {
        return $item->store_id !== null && self::isMedicine($item) && $this->storeBlocksPromotions((int) $item->store_id);
    }

    public function storeBlocksPromotions(int $storeId): bool
    {
        $facts = $this->storeFacts($storeId);

        return $facts['pharmacy'] && self::marketRules($facts['country'])['block_medicine_promotions'];
    }

    public function reason(): string
    {
        return ___('Discounts and loyalty points do not apply to medicines in this market.');
    }

    /** Forget the per-store memo (a licence upload can move the store's market). */
    public function flush(): void
    {
        $this->facts = [];
    }

    /**
     * The store's type and market, memoised for a short while: the per-item questions above run once per menu item
     * on a render, and the market needs three reads. Rules themselves are read from config on every call.
     *
     * @return array{at: int, pharmacy: bool, country: ?string}
     */
    private function storeFacts(int $storeId): array
    {
        $memo = $this->facts[$storeId] ?? null;
        if ($memo !== null && time() - $memo['at'] < self::MEMO_SECONDS) {
            return $memo;
        }
        $store = Store::query()->withoutGlobalScopes()->find($storeId);
        $pharmacy = $store !== null && self::isPharmacy($store);

        return $this->facts[$storeId] = [
            'at' => time(),
            'pharmacy' => $pharmacy,
            'country' => $pharmacy ? $this->country($store) : null,
        ];
    }

    /** Units of one medicine a customer may order at once: the market's cap, or the store's lower one. */
    public function quantityCap(Store $store): int
    {
        $cap = self::marketRules($this->country($store))['quantity_cap'];
        $own = (int) store_options((int) $store->id, self::OPTION_QUANTITY_CAP, 0);

        return $own > 0 ? min($cap, $own) : $cap;
    }

    public function unpublishedReason(Store $store): ?string
    {
        if (! self::isPharmacy($store)) {
            return null;
        }
        $country = $this->country($store);
        if ($country === null) {
            return 'pharmacy_market_unknown';
        }
        if (! self::countryEnabled($country)) {
            return 'pharmacy_market_disabled';
        }
        $licence = PharmacyLicence::latestFor((int) $store->id);

        return match (true) {
            $licence === null => 'pharmacy_licence_missing',
            $licence->status === PharmacyLicence::PENDING => 'pharmacy_licence_pending',
            $licence->status === PharmacyLicence::REJECTED => 'pharmacy_licence_rejected',
            $licence->isExpired() => 'pharmacy_licence_expired',
            default => null,
        };
    }

    /**
     * P1-23: keep pharmacy stores that may not be shown ({@see unpublishedReason()}) off a public listing query (the
     * marketplace fence, Store::scopeMkApprovedFor). No enabled country → no pharmacy at all, in SQL; otherwise only
     * pharmacies with an approved licence can qualify, and those few are checked one by one.
     *
     * @param  Builder<Store>  $query
     */
    public function restrictPublicListing(Builder $query): void
    {
        $type = $query->qualifyColumn('store_type');
        $published = [];
        if (self::enabledCountries() !== []) {
            // A platform publish rule: read across the tenant scope (as PharmacyLicence::latestFor()).
            $candidates = Store::query()->withoutGlobalScopes()->where('store_type', self::STORE_TYPE)
                ->whereIn('id', PharmacyLicence::query()->withoutGlobalScopes()->where('status', PharmacyLicence::APPROVED)->select('store_id'))
                ->get();
            foreach ($candidates as $store) {
                if ($this->unpublishedReason($store) === null) {
                    $published[] = (int) $store->id;
                }
            }
        }

        $query->where(function (Builder $q) use ($type, $published) {
            $q->whereNull($type)->orWhere($type, '!=', self::STORE_TYPE);
            if ($published !== []) {
                $q->orWhereIn($q->qualifyColumn('id'), $published);
            }
        });
    }

    public function orderRefusal(Store $store, array $lines): ?string
    {
        if (! self::isPharmacy($store)) {
            return null;
        }
        if ($this->unpublishedReason($store) !== null) {
            return ___('This pharmacy is not available for online orders.');
        }
        $cap = $this->quantityCap($store);
        $totals = [];
        foreach ($lines as $line) {
            $menu = $line['menu'];
            if (! (bool) ($menu->is_medicine ?? true)) {
                continue;
            }
            $totals[$menu->id] = ($totals[$menu->id] ?? 0) + (int) $line['quantity'];
            if ($totals[$menu->id] > $cap) {
                return ___('You can order at most :max of ":item" in one order.', [
                    'max' => $cap, 'item' => (string) $menu->name,
                ]);
            }
        }

        return null;
    }

    public function promotionsBlocked(Store $store, iterable $items): bool
    {
        if (! $this->medicinePromotionsBlocked($store)) {
            return false;
        }
        foreach ($items as $item) {
            if (self::isMedicine($item)) {
                return true;
            }
        }

        return false;
    }

    /** Owner-facing explanation of an unpublished reason. */
    public static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            null => ___('Published: pharmacy selling is enabled in your country and your licence is approved.'),
            'pharmacy_market_unknown' => ___('Not published: add your licence (with its issuing country) so we know your market.'),
            'pharmacy_market_disabled' => ___('Not published: pharmacy selling is not enabled in your country yet.'),
            'pharmacy_licence_missing' => ___('Not published: upload your pharmacy licence documents.'),
            'pharmacy_licence_pending' => ___('Not published: your licence documents are waiting for review.'),
            'pharmacy_licence_rejected' => ___('Not published: your licence documents were not accepted. Upload corrected documents.'),
            'pharmacy_licence_expired' => ___('Not published: your licence has expired. Upload the renewed licence.'),
            default => ___('Not published.'),
        };
    }
}
