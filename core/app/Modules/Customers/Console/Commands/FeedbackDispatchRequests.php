<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Console\Commands;

use App\Models\Order;
use Juwri\Modules\Customers\Services\FeedbackService;
use Illuminate\Console\Command;

/**
 * SYSTEMS-RUN S-P1: create feedback requests for orders completed at least
 * {--delay} hours ago (idempotent, one per order). The tokened survey link is
 * then delivered via the restaurant's configured channel.
 */
class FeedbackDispatchRequests extends Command
{
    protected $signature = 'feedback:dispatch {--delay=2}';

    protected $description = 'Create post-order feedback requests.';

    public function handle(FeedbackService $service): int
    {
        $cutoff = now()->subHours((int) $this->option('delay'));
        $created = 0;

        Order::whereIn('status', ['completed', 'delivered'])
            ->whereNotNull('customer_id')
            ->where('updated_at', '<=', $cutoff)
            ->where('updated_at', '>=', now()->subDays(3))
            ->chunkById(200, function ($orders) use ($service, &$created) {
                foreach ($orders as $order) {
                    if ($service->requestForOrder($order)) {
                        $created++;
                    }
                }
            });

        $this->info("Feedback requests ensured: {$created}.");
        return self::SUCCESS;
    }
}
