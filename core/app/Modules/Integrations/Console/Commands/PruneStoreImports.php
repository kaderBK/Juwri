<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Integrations\Models\StoreImport;

/**
 * P6-316: an uploaded backup ZIP (up to 500 MB) that was previewed and never confirmed, or that failed or finished,
 * is deleted after 48 hours. Rows of a running import are never touched.
 */
class PruneStoreImports extends Command
{
    protected $signature = 'store-imports:prune';

    protected $description = 'Delete store-import uploads older than 48 hours that are not running (P6-316)';

    public function handle(): int
    {
        if (! Schema::hasTable('store_imports')) {
            return self::SUCCESS;
        }

        $n = 0;
        StoreImport::query()
            ->where('created_at', '<', now()->subHours(48))
            ->where('status', '!=', 'processing')
            ->whereNotNull('path')
            ->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $import) {
                    @unlink(storage_path('app/'.$import->path));
                    $import->forceFill(['path' => null])->save();
                    $n++;
                }
            });
        $this->info("Pruned {$n} store import upload(s).");

        return self::SUCCESS;
    }
}
