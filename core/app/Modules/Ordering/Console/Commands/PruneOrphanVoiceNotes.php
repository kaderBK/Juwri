<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Juwri\Modules\Media\Contracts\StorageQuotas;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;

/**
 * FEATURES-5 Track C: purge orphaned temp voice-note uploads.
 *
 * A voice note is created when the customer records at checkout, BEFORE the
 * order is placed. If they never complete the order the row stays unattached
 * (order_id NULL). This command deletes such rows older than 24h along with
 * their private-disk files and releases the tenant's storage quota.
 */
class PruneOrphanVoiceNotes extends Command
{
    protected $signature = 'voice-notes:prune {--hours=24}';
    protected $description = 'Delete orphaned (unattached, >24h) customer voice-note uploads and their files';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $orphans = OrderVoiceNote::whereNull('order_id')
            ->where('created_at', '<', $cutoff)
            ->get();

        $disk = Storage::disk('local');
        $count = 0;

        foreach ($orphans as $note) {
            $rel = $note->relativePath();
            if ($disk->exists($rel)) {
                $disk->delete($rel);
            }
            $ownerId = (int) (\Juwri\Core\Models\Store::where('id', $note->store_id)->value('user_id') ?: 0);
            if ($ownerId && $note->size_bytes > 0) {
                app(StorageQuotas::class)->sub($ownerId, (int) $note->size_bytes);
            }
            $note->delete();
            $count++;
        }

        $this->info('Pruned ' . $count . ' orphaned voice note(s).');

        return self::SUCCESS;
    }
}
