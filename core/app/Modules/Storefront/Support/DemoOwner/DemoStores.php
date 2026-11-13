<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Support\DemoOwner;

use App\Models\StoreOption;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;

/**
 * J62-1: which stores belong to the owner demo. Two natural keys: the slugs the plan fixes
 * ({@see DemoOwnerPlan::STORES}) and the demo tag every demo store carries as a store option
 * ({@see DemoOwnerPlan::TAG_OPTION}).
 *
 * The tag is what lets `--fresh` find the demo stores again after a new store was added to the
 * plan, and what tells the demo's own rows apart from a store the owner made himself.
 */
final class DemoStores
{
    /**
     * Every demo store: the plan's slugs plus the stores carrying the demo tag.
     *
     * @return list<Store>
     */
    public function existing(): array
    {
        $ids = Store::query()
            ->whereIn('slug', array_values(DemoOwnerPlan::STORES))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $tagged = StoreSettings::storeIdsWhere(
            DemoOwnerPlan::TAG_OPTION,
            fn (string $value): bool => $value === DemoOwnerPlan::TAG_VALUE
        );

        $ids = array_values(array_unique(array_merge($ids, $tagged)));
        sort($ids);

        $stores = [];
        foreach (Store::query()->whereIn('id', $ids)->get() as $store) {
            $stores[] = $store;
        }

        return $stores;
    }

    /**
     * The slugs of the demo stores that exist right now.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $slugs = [];

        foreach ($this->existing() as $store) {
            $slug = (string) $store->slug;
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /** Mark a store as demo-owned (idempotent: the tag is one store option). */
    public function tag(Store $store): void
    {
        StoreOption::updateStoreOption((int) $store->id, DemoOwnerPlan::TAG_OPTION, DemoOwnerPlan::TAG_VALUE);
    }
}
