<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Console\Commands;

use App\Services\Marketing\CartRecoveryService;
use Illuminate\Console\Command;

/**
 * GAPS-RUN-2 B2-P1: multi-channel abandoned-cart recovery (WhatsApp + email + SMS).
 * Supersedes whatsapp:cart-recovery (which now delegates here for BC).
 */
class CartRecovery extends Command
{
    protected $signature = 'marketing:cart-recovery';

    protected $description = 'Send multi-channel reminders for unpaid (abandoned) orders';

    public function handle(CartRecoveryService $service): int
    {
        $nudged = $service->run();
        $this->info("Cart recovery: {$nudged} order(s) nudged.");
        return self::SUCCESS;
    }
}
