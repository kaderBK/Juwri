<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** P6-295: finish the items.name_search backfill outside the web request (a migration cut off by a host time limit). */
class BackfillItemNameSearchCommand extends Command
{
    protected $signature = 'juwri:items:backfill-name-search';

    protected $description = 'Fill items.name_search (normalised item names for Arabic-aware search) for rows that have none; safe to re-run.';

    public function handle(): int
    {
        if (! Schema::hasColumn('items', 'name_search')) {
            $this->error('items.name_search does not exist yet - run php artisan migrate first.');

            return self::FAILURE;
        }

        $migration = require base_path('app/Modules/Menu/Database/Migrations/2026_10_19_120000_add_name_search_to_items.php');
        $migration->backfill();
        $this->info('items.name_search backfill complete.');

        return self::SUCCESS;
    }
}
