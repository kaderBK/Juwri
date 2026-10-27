<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Console\Commands;

use App\Services\GiftCardService;
use Illuminate\Console\Command;

/**
 * B2: flip lapsed gift cards from 'active' to 'expired'. isRedeemable() already
 * rejects a past-expiry card, but its status column stays 'active', so reporting
 * and the owner admin (C5) can't see it as expired. The sweep is idempotent and
 * chunked — delegates to GiftCardService::expireLapsed(). Scheduled daily.
 */
class ExpireGiftCards extends Command
{
    protected $signature = 'giftcards:expire';

    protected $description = 'Flip active gift cards past their expiry date to status=expired (B2)';

    public function handle(): int
    {
        $count = GiftCardService::expireLapsed();
        $this->info("Expired {$count} lapsed gift card(s).");

        return self::SUCCESS;
    }
}
