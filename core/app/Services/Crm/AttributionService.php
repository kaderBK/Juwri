<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Crm;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * SYSTEMS-RUN C-P2: campaign attribution. An order is attributed to a campaign
 * when the recipient placed a paid order within $windowDays of their send. A
 * revenue-per-campaign figure the owner can weigh spend against.
 */
class AttributionService
{
    public function __construct(private int $windowDays = 7) {}

    /** @return array{orders:int, revenue:float} */
    public function forCampaign(Campaign $campaign): array
    {
        $orders = 0;
        $revenue = 0.0;

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereNotNull('customer_id')->whereNotNull('sent_at')->get();

        foreach ($recipients as $rec) {
            $windowEnd = Carbon::parse($rec->sent_at)->addDays($this->windowDays);
            $matched = Order::where('customer_id', $rec->customer_id)
                ->where('is_paid', 1)
                ->whereBetween('created_at', [$rec->sent_at, $windowEnd])
                ->get();
            foreach ($matched as $o) {
                $orders++;
                $revenue += (float) $o->total;
            }
        }

        return ['orders' => $orders, 'revenue' => round($revenue, 2)];
    }

    /** Attribution for every campaign of a restaurant. */
    public function report(int $storeId): array
    {
        $out = [];
        foreach (Campaign::where('store_id', $storeId)->latest()->limit(50)->get() as $c) {
            $out[] = ['campaign' => $c] + $this->forCampaign($c);
        }

        return $out;
    }
}
