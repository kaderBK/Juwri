<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Menu\Models\Menu;

/**
 * D4: permanently delete menu items trashed more than --days ago. forceDelete()
 * fires Menu's forceDeleted hook, which ref-count-unlinks the images (a shared
 * filename still referenced by a live item is NOT removed).
 *
 * An item that order lines still name is never purged (it stays trashed): order history keeps its item, and the
 * lines are not touched (their key would only be set NULL, but the item row is the line's name and picture).
 */
class PurgeTrashedMenu extends Command
{
    protected $signature = 'menu:purge-trashed {--days=30}';

    protected $description = 'Force-delete menu items trashed more than N days ago (D4)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $purged = 0;
        $kept = 0;

        Menu::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($items) use (&$purged, &$kept) {
                $ordered = array_flip(array_map('intval', DB::table('order_items')
                    ->whereIn('item_id', $items->modelKeys())->distinct()->pluck('item_id')->all()));
                foreach ($items as $item) {
                    if (isset($ordered[(int) $item->getKey()])) {
                        $kept++;

                        continue;
                    }
                    $item->forceDelete(); // fires the image-cleanup hook once, ref-counted
                    $purged++;
                }
            });

        $this->info("Purged {$purged} trashed menu item(s) older than {$days} day(s); kept {$kept} still named by orders.");

        return self::SUCCESS;
    }
}
