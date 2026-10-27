<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fitment;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P3-08b: the products of ONE store by SKU (the item's `external_sku` or a variant's `sku`, case-insensitive) and by
 * public id, loaded once per import. A product of another store is simply not in it, whatever the file says.
 */
final class FitmentProductIndex
{
    /** @var array<string, int|'many'> */
    private array $skus = [];

    /** @var array<string, int> */
    private array $publicIds = [];

    public function __construct(Store $store)
    {
        $id = (int) $store->id;
        Menu::query()->where('store_id', $id)->select(['id', 'public_id', 'external_sku'])->orderBy('id')->chunk(2000, function ($items) {
            foreach ($items as $item) {
                if ($item->public_id !== null) {
                    $this->publicIds[strtolower((string) $item->public_id)] = (int) $item->id;
                }
                $this->add((string) $item->external_sku, (int) $item->id);
            }
        });
        $variants = DB::table('menu_variants as v')
            ->join('items as m', 'm.id', '=', 'v.menu_id')
            ->where('m.store_id', $id)->where('v.store_id', $id)->whereNull('m.deleted_at')
            ->whereNotNull('v.sku')->orderBy('v.id')->select(['v.sku', 'v.menu_id']);
        foreach ($variants->cursor() as $row) {
            $this->add((string) $row->sku, (int) $row->menu_id);
        }
    }

    /** @return int|'many'|null */
    public function bySku(string $sku): int|string|null
    {
        return $this->skus[strtolower(trim($sku))] ?? null;
    }

    public function byPublicId(string $publicId): ?int
    {
        return $this->publicIds[strtolower(trim($publicId))] ?? null;
    }

    private function add(string $sku, int $menuId): void
    {
        $key = strtolower(trim($sku));
        if ($key === '') {
            return;
        }
        $this->skus[$key] = isset($this->skus[$key]) && $this->skus[$key] !== $menuId ? 'many' : $menuId;
    }
}
