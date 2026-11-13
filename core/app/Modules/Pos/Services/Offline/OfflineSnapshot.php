<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use App\Models\MenuCategory;
use App\Models\StaffMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineSnapshot;

/**
 * The catalogue snapshot a till keeps to sell offline (the offline POS spec §2).
 *
 * Versioning is by content: the snapshot is built from the live data, its canonical JSON hashed, and a new version
 * (latest + 1) is stored only when the hash differs from the latest version of that store and branch. So every change
 * that matters to the till (a price, an item, a tax rate, a staff member, a setting) makes a new version without an
 * observer on each model, and a replayed sale can be re-priced with exactly the version it was sold with (kept 30 days).
 *
 * Contents are ONE store's and ONE branch's data only, prices in minor units of the store currency, names raw (the
 * device shows its own language from `translations`). Never: another store's rows, customers, secrets, staff PINs (the
 * PIN check comes with device registration, spec OP-2, hashed with a per-device pepper).
 */
final class OfflineSnapshot
{
    public const SCHEMA = 1;

    public const KEEP_DAYS = 30;

    /** The current version for this store and branch, storing a new one when the content changed. */
    public function current(Store $store, int $branchId): PosOfflineSnapshot
    {
        $parts = $this->parts($store, $branchId);
        $json = self::canonical($parts);
        $hash = hash('sha256', $json);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $latest = $this->latest((int) $store->id, $branchId);
            if ($latest !== null && hash_equals($latest->hash, $hash)) {
                return $latest;
            }
            try {
                $row = PosOfflineSnapshot::query()->create([
                    'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branchId,
                    'version' => ($latest->version ?? 0) + 1, 'hash' => $hash, 'payload' => $json, 'created_at' => now(),
                ]);
                $this->prune((int) $store->id, $branchId);

                return $row;
            } catch (UniqueConstraintViolationException) {
                continue; // another till stored this version at the same moment: read it again
            }
        }

        return $this->latest((int) $store->id, $branchId) ?? throw new \RuntimeException('No offline snapshot could be stored.');
    }

    /** A stored version (replay re-prices with it); null when unknown or pruned. */
    public function version(int $storeId, int $branchId, int $version): ?PosOfflineSnapshot
    {
        return PosOfflineSnapshot::query()->where('store_id', $storeId)->where('branch_id', $branchId)->where('version', $version)->first();
    }

    /**
     * The document the till downloads. `issued_at` / `valid_until` are outside the hash: the till may sell offline until
     * `valid_until` (the store's offline hours after it last reached the server, owner Q2: default 12 h, max 24 h).
     *
     * @return array<string, mixed>
     */
    public static function document(Store $store, PosOfflineSnapshot $row, ?PosOfflineDevice $device = null): array
    {
        $issued = now();
        $parts = json_decode($row->payload, true);
        $deviceBlock = $device === null ? null : OfflineDevices::status($device) + [
            'pin_verifiers' => OfflineDevices::pinVerifiers($device, $store, array_map(fn ($s) => (int) $s['id'], (array) ($parts['staff'] ?? []))),
        ];

        return [
            'schema' => self::SCHEMA, 'store_id' => (int) $row->store_id, 'branch_id' => $row->branch_id ?: null,
            'version' => $row->version, 'hash' => $row->hash, 'built_at' => $row->created_at?->toIso8601ZuluString(),
            'issued_at' => $issued->toIso8601ZuluString(), 'offline_hours' => self::offlineHours($store), 'valid_until' => $issued->copy()->addHours(self::offlineHours($store))->toIso8601ZuluString(),
            'parts' => $parts,
            'device' => $deviceBlock, // per till, outside the hash: lease + PIN verifiers peppered for THIS till
        ];
    }

    /** Owner Q2: the store's offline hours (default 12), never above the platform maximum (24). */
    public static function offlineHours(Store $store): int
    {
        $max = max(1, (int) config('pos.offline.max_hours', 24));
        $hours = (int) (store_options((int) $store->id, 'pos_offline_hours') ?: config('pos.offline.default_hours', 12));

        return min($max, max(1, $hours));
    }

    /** @return array<string, mixed> */
    public function parts(Store $store, int $branchId): array
    {
        $currency = Currencies::normalize((string) (store_options((int) $store->id, 'currency_code') ?: config('settings.currency_code', 'USD')));
        $minor = fn ($major): int => Money::minorFromMajor((string) ($major ?? 0), $currency);

        return [
            'store' => $this->store($store, $currency),
            'catalogue' => $this->catalogue($store, $branchId, $minor),
            'taxes' => $this->taxes($store, $minor),
            'staff' => $this->staff($store, $branchId),
            'settings' => $this->settings($store),
            'caps' => self::caps($store, $currency),
        ];
    }

    /** @return array<string, mixed> */
    private function store(Store $store, string $currency): array
    {
        return ['id' => (int) $store->id, 'title' => (string) $store->getRawOriginal('title'), 'currency' => $currency,
            'exponent' => Currencies::exponent($currency), 'rounding' => 'half_up'];
    }

    /**
     * Categories and items as the till sells them at this branch: the branch price, priced variants, extras (with the
     * per-item price override) and choice groups — the same modifiers the order path prices (PosController::menu()).
     * Scheduled (happy-hour) prices are not in v1: offline sales use the branch price (spec §2, known limit).
     *
     * @param  \Closure(mixed): int  $minor
     * @return array<string, mixed>
     */
    private function catalogue(Store $store, int $branchId, \Closure $minor): array
    {
        $categories = MenuCategory::query()->where('store_id', $store->id)->where('active', '1')->orderBy('position')->orderBy('id')->get();
        $items = Menu::query()->where('store_id', $store->id)->where('active', '1')->orderBy('id')
            ->with(['variants', 'branchSettings', 'attachedExtras', 'extras', 'attachedVariantOptions', 'variantOptions'])->get();
        $promotionRules = app(PromotionRules::class);
        $rows = [];
        foreach ($items as $m) {
            if ($m->availableAtBranch($branchId ?: null)) {
                $rows[] = $this->item($m, $branchId, $minor, $promotionRules->blocksPromotions($m));
            }
        }

        return [
            'categories' => $categories->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->getRawOriginal('name'),
                'position' => (int) $c->position])->values()->all(),
            'items' => $rows,
        ];
    }

    /** The option id the snapshot and the live POS menu share: choice options have no row of their own. */
    public static function optionId(int $groupId, int $position): string
    {
        return $groupId.':'.$position;
    }

    /**
     * @param  \Closure(mixed): int  $minor
     * @return array<string, mixed>
     */
    private function item(Menu $m, int $branchId, \Closure $minor, bool $noPromotions): array
    {
        $groups = [];
        foreach ($m->resolved_variant_options->sortBy('id') as $g) {
            $labels = array_values((array) json_decode((string) json_encode($g->options), true));
            $prices = (array) ($g->option_prices ?? []);
            $options = [];
            foreach ($labels as $i => $label) {
                // id = '<group id>:<position>' — the same id the live POS menu sends, so a till matches an option by id, never by label
                $options[] = ['id' => self::optionId((int) $g->id, (int) $i), 'label' => $label, 'price_minor' => $minor($prices[$i] ?? 0)];
            }
            $groups[] = ['id' => (int) $g->id, 'title' => (string) $g->getRawOriginal('title'), 'required' => (bool) $g->required,
                'min_select' => (int) ($g->min_select ?? 0), 'max_select' => (int) ($g->max_select ?? 0), 'options' => $options];
        }
        $variants = [];
        foreach ($m->variants->where('active', true)->sortBy('id') as $v) {
            $variants[] = ['id' => (int) $v->id, 'price_minor' => $minor($v->price), 'options' => $v->options];
        }
        $extras = [];
        foreach ($m->resolved_extras->sortBy('id') as $e) {
            $extras[] = ['id' => (int) $e->id, 'title' => (string) $e->getRawOriginal('title'), 'price_minor' => $minor($e->price)];
        }

        return [
            'id' => (int) $m->id,
            'category_id' => (int) $m->category_id,
            'name' => (string) $m->getRawOriginal('name'),
            'translations' => json_decode((string) ($m->getRawOriginal('translations') ?? 'null'), true),
            'plu' => $m->plu !== null ? (string) $m->plu : null,
            'price_minor' => $minor($m->priceForBranch($branchId ?: null)),
            'sold_out' => (int) $m->is_sold_out === 1,
            'no_promotions' => $noPromotions,
            'stock' => $m->track_inventory && $m->stock_qty !== null ? ['qty' => (int) $m->stock_qty] : null,
            'variants' => $variants,
            'extras' => $extras,
            'choice_groups' => $groups,
        ];
    }

    /**
     * The store's tax as the server applies it to a till sale (Order::computeTax): one exclusive tax on the full
     * pre-discount item subtotal — a percentage rounded half-up to the currency, or a fixed amount.
     *
     * @param  \Closure(mixed): int  $minor
     * @return array<string, mixed>
     */
    private function taxes(Store $store, \Closure $minor): array
    {
        $opts = store_options((int) $store->id);
        $charge = (float) (@$opts->restaurant_taxx_charge ?: 0);
        $type = (string) (@$opts->restaurant_tax_type ?: 'percentage');

        return $charge <= 0 ? ['mode' => 'none'] : ($type === 'fixed'
            ? ['mode' => 'fixed', 'amount_minor' => $minor($charge), 'base' => 'subtotal_before_discount']
            : ['mode' => 'percentage', 'rate' => (string) $charge, 'base' => 'subtotal_before_discount']);
    }

    /**
     * Active staff of this store who may work at this branch, with their effective POS permissions.
     *
     * @return list<array<string, mixed>>
     */
    private function staff(Store $store, int $branchId): array
    {
        $perms = array_values(array_unique(array_merge(...array_values(StaffMember::ROLE_PERMISSIONS))));
        sort($perms);

        return StaffMember::query()->where('store_id', $store->id)->where('active', 1)
            ->when($branchId > 0, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('id')->get()
            ->map(fn (StaffMember $s) => ['id' => (int) $s->id, 'name' => (string) $s->name, 'role' => (string) $s->role,
                'permissions' => array_values(array_filter($perms, fn ($p) => $s->hasPermission($p))),
                // a PIN change makes a new version (the verifiers themselves are per till, outside the hash)
                'pin_rev' => $s->pin_offline_kdf !== null ? substr(hash('sha256', (string) $s->pin_offline_kdf), 0, 12) : null])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function settings(Store $store): array
    {
        return ['retention_days' => (int) config('pos.offline.retention_days', 7), // owner Q5
            'oversell' => 'allow_and_reconcile']; // owner Q4
    }

    /**
     * Offline card caps (owner Q3: SAR 250 per payment, SAR 2,500 unforwarded total per till) — platform maxima in the
     * store currency; OFF until the provider question (Q1) is answered and the platform switch is on.
     *
     * @return array<string, mixed>
     */
    public static function caps(Store $store, string $currency): array
    {
        [$per, $total] = self::cardCaps($store);

        return ['card_offline_enabled' => (bool) config('pos.offline.card_enabled', false) && app(PaymentTerminal::class)->supportsOffline($store), 'currency' => $currency,
            'card_max_amount_minor' => $per, 'card_max_total_minor' => $total,
            'cash_only_without_shell' => true]; // owner Q8: browser-only tills sell offline with cash / manual tenders only
    }

    /**
     * The offline card caps in force for a store (minor units): the platform maxima (owner Q3: SAR 250 per payment,
     * SAR 2,500 unforwarded per till), or the store's own LOWER values (post options pos_offline_card_max_amount_minor /
     * pos_offline_card_max_total_minor). A store can never raise them.
     *
     * @return array{0: int, 1: int}
     */
    public static function cardCaps(Store $store): array
    {
        $per = (int) config('pos.offline.card_max_amount_minor', 25000);
        $total = (int) config('pos.offline.card_max_total_minor', 250000);
        $ownPer = store_options((int) $store->id, 'pos_offline_card_max_amount_minor');
        $ownTotal = store_options((int) $store->id, 'pos_offline_card_max_total_minor');

        return [is_numeric($ownPer) && (int) $ownPer >= 0 ? min($per, (int) $ownPer) : $per, is_numeric($ownTotal) && (int) $ownTotal >= 0 ? min($total, (int) $ownTotal) : $total];
    }

    private function latest(int $storeId, int $branchId): ?PosOfflineSnapshot
    {
        return PosOfflineSnapshot::query()->where('store_id', $storeId)->where('branch_id', $branchId)->orderByDesc('version')->first();
    }

    private function prune(int $storeId, int $branchId): void
    {
        $latest = (int) PosOfflineSnapshot::query()->where('store_id', $storeId)->where('branch_id', $branchId)->max('version');
        PosOfflineSnapshot::query()->where('store_id', $storeId)->where('branch_id', $branchId)->where('version', '<', $latest)
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /** Canonical JSON: keys sorted at every level, so the same content always hashes the same. */
    public static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
