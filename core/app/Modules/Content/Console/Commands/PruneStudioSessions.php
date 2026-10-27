<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Juwri\Modules\Content\Models\StudioSession;
use Illuminate\Console\Command;

/**
 * Guest-privacy purge: delete expired Menu Studio guest sessions and their
 * uploaded files. Sessions that migrated to an account (user_id set) are kept.
 */
class PruneStudioSessions extends Command
{
    protected $signature = 'studio:prune';
    protected $description = 'Delete expired Menu Studio guest sessions and their files';

    public function handle(): int
    {
        $expired = StudioSession::whereNull('user_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $session) {
            $session->purgeFiles();
            $session->delete();
        }

        $this->info('Pruned '.$expired->count().' expired studio session(s).');

        return self::SUCCESS;
    }
}
