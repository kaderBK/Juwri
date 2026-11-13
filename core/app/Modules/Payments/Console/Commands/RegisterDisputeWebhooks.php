<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;

/**
 * GAPS-RUN-2 B11-P1: register a charge.dispute.* webhook endpoint on each
 * restaurant's BYOK Stripe account and store the endpoint signing secret in the
 * Credential vault. LIVE-DEFERRED — disputes fire on each merchant's own account,
 * so this needs real BYOK keys; the scaffold documents the flow and no-ops without
 * them so CI/scheduling never breaks.
 */
class RegisterDisputeWebhooks extends Command
{
    protected $signature = 'stripe:register-dispute-webhooks';

    protected $description = 'Register charge.dispute.* webhook endpoints per BYOK Stripe account (live-deferred)';

    public function handle(): int
    {
        // For each restaurant with a BYOK Stripe secret key:
        //   POST /v1/webhook_endpoints { enabled_events: ["charge.dispute.*"], url }
        //   store the returned endpoint secret in Credential (feature=stripe_dispute).
        // Deferred until live keys are provided; intentionally a no-op here.
        $this->info('Dispute webhook registration is live-deferred (no BYOK keys configured).');
        return self::SUCCESS;
    }
}
