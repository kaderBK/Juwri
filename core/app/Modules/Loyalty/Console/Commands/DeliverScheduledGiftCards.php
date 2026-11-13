<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Console\Commands;

use App\Services\GiftCardService;
use Illuminate\Console\Command;

/**
 * T2-P3: deliver active gift cards whose scheduled send-time has arrived (birthday
 * sends, etc.) and retry any that failed their first delivery attempt. Idempotent —
 * delivered_at guards against re-sending.
 */
class DeliverScheduledGiftCards extends Command
{
    protected $signature = 'giftcards:deliver-scheduled';

    protected $description = 'Deliver gift cards whose scheduled send-time has arrived (and retry failures)';

    public function handle(): int
    {
        $n = GiftCardService::deliverDue();
        if ($n > 0) {
            $this->info("Delivered {$n} gift card(s).");
        }
        return self::SUCCESS;
    }
}
