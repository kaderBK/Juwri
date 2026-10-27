<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Console\Commands;

use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Models\WhatsAppLog;
use Illuminate\Console\Command;

/**
 * Data-retention purge: strips message bodies from inbound threads and outbound
 * logs older than whatsapp.retention_days. Counts/metadata are preserved.
 * (spec §15.7)
 */
class PurgeWhatsAppData extends Command
{
    protected $signature = 'whatsapp:purge';

    protected $description = 'Redact WhatsApp message bodies past the retention window';

    public function handle(): int
    {
        $days = config('whatsapp.retention_days');
        if ($days === null || $days === '' || (int) $days <= 0) {
            $this->info('WhatsApp retention disabled (whatsapp.retention_days is null).');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);

        $inbound = WhatsAppInbound::where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('body')->orWhereNotNull('media');
            })
            ->update(['body' => null, 'media' => null]);

        $logs = WhatsAppLog::where('created_at', '<', $cutoff)
            ->whereNotNull('body_preview')
            ->update(['body_preview' => null]);

        $this->info("Purged bodies: {$inbound} inbound, {$logs} log rows (older than {$days}d).");

        return self::SUCCESS;
    }
}
