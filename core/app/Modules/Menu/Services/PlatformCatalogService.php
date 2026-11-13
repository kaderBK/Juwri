<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services;

use Juwri\Modules\Menu\Models\GlobalMenuCategory;
use Juwri\Modules\Menu\Models\GlobalMenuExtra;
use App\Models\MenuCategory;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Core\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * WI-G4: copy-on-adopt of the platform catalog into a store's own scoped rows.
 *
 * Adopting NEVER shares a platform row cross-tenant — it copies the global row
 * into a MenuCategory/MenuExtra scoped to the adopting restaurant, stamped with
 * source_global_id. A second adopt of the same global row updates that copy in
 * place (matched by source_global_id) instead of duplicating it, so the owner
 * can pull curated updates without losing their own edits' identity.
 */
class PlatformCatalogService
{
    /**
     * Adopt a global category (and its sub-categories) into $store. Returns the
     * number of category rows created/updated.
     */
    public function adoptCategory(GlobalMenuCategory $global, Store $store, ?int $parentLocalId = null): array
    {
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($global, $store, $parentLocalId, &$created, &$updated) {
            $cat = MenuCategory::firstOrNew([
                'store_id'    => $store->id,
                'source_global_id' => $global->id,
            ]);
            $existed = $cat->exists;
            $cat->fill([
                'name'     => $global->name,
                'position' => $global->position,
                'active'   => $cat->active ?? '1',
                'parent'   => $parentLocalId,
            ]);
            if ($global->translations) {
                $cat->translations = $global->translations;
            }
            $cat->save();
            $existed ? $updated++ : $created++;

            foreach (GlobalMenuCategory::where('parent_id', $global->id)->get() as $child) {
                $sub = $this->adoptCategory($child, $store, $cat->id);
                $created += $sub['created'];
                $updated += $sub['updated'];
            }
        });

        return compact('created', 'updated');
    }

    /** Adopt a global extra/add-on into $store. */
    public function adoptExtra(GlobalMenuExtra $global, Store $store, ?int $menuId = null): array
    {
        $extra = MenuExtra::firstOrNew([
            'store_id'    => $store->id,
            'source_global_id' => $global->id,
        ]);
        $existed = $extra->exists;
        $extra->fill([
            'title'    => $global->title,
            'price'    => $global->price,
            'position' => $global->position,
            'menu_id'  => $menuId,
        ]);
        if ($global->translations) {
            $extra->translations = $global->translations;
        }
        $extra->save();

        return ['created' => $existed ? 0 : 1, 'updated' => $existed ? 1 : 0];
    }
}
