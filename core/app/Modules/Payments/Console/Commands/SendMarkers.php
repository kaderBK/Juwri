<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Payments\Services\Webhooks\SendOnce;

/**
 * P6-158 review: the operator's view of send-once markers. A job killed mid-send leaves its marker in `sending` and
 * the send is never retried on its own (owner trade-off: a rare missed email / ticket instead of a duplicate). This
 * lists those markers and releases one by id, so the next outbox re-run or scheduled tick sends it again.
 */
class SendMarkers extends Command
{
    protected $signature = 'payments:send-markers {--release= : id of a stuck `sending` marker to release (re-issue)} {--all : list `sent` markers too}';

    protected $description = 'List stuck outbox send-once markers (email / print cut off mid-send) and release one for re-issue.';

    public function handle(): int
    {
        if ($this->option('release') !== null) {
            $id = (int) $this->option('release');
            if ($id < 1 || ! SendOnce::releaseStuck($id)) {
                $this->error("no stuck (sending) marker with id {$id}; a sent marker is never released");

                return self::FAILURE;
            }
            $this->info("released marker {$id}: the next outbox re-run or scheduled tick sends it again");

            return self::SUCCESS;
        }

        $rows = DB::table(SendOnce::TABLE)
            ->when(! $this->option('all'), fn ($q) => $q->where('state', 'sending'))
            ->orderBy('id')->limit(500)
            ->get(['id', 'message_type', 'entity_key', 'state', 'created_at']);
        if ($rows->isEmpty()) {
            $this->info($this->option('all') ? 'no send markers' : 'no stuck send markers');

            return self::SUCCESS;
        }
        $this->table(['id', 'type', 'key', 'state', 'claimed at'], $rows->map(fn ($r) => (array) $r)->all());

        return self::SUCCESS;
    }
}
