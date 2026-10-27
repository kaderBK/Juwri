<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Console\Commands;

use App\Services\Marketing\CartRecoveryService;
use Illuminate\Console\Command;

/**
 * Abandoned-cart recovery (legacy signature). Kept for backward compatibility —
 * delegates to the multi-channel CartRecoveryService (B2-P1). Owner opt-ins per
 * channel (customer_cart_whatsapp/email/sms) decide what actually goes out, so an
 * owner who only enabled WhatsApp gets exactly the old behavior. (spec §14.7)
 */
class WhatsAppCartRecovery extends Command
{
    protected $signature = 'whatsapp:cart-recovery';

    protected $description = 'Send reminders for unpaid (abandoned) orders (delegates to marketing:cart-recovery)';

    public function handle(CartRecoveryService $service): int
    {
        $sent = $service->run();
        $this->info("Cart recovery: {$sent} reminder(s) queued.");
        return self::SUCCESS;
    }
}
