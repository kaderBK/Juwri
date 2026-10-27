<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Jobs;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Services\Crm\AudienceService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(public int $campaignId) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->status !== 'scheduled') {
            return;
        }

        $campaign->update(['status' => 'sending']);

        /* Build target audience query.
         *
         * `marketing_consent` is the broad opt-in; `sms_consent` is the separate,
         * narrower permission to text someone. Filtering on the broad flag alone
         * sent SMS marketing to customers who had explicitly declined SMS while
         * accepting email — the exact distinction the second column exists to
         * record, and a consent breach rather than a cosmetic bug. */
        $query = Customer::where('store_id', $campaign->store_id)
            ->where('marketing_consent', true);

        $smsCampaign = $campaign->channel === 'sms';
        if ($smsCampaign) {
            $query->where('sms_consent', true);
        }

        $now = Carbon::now();

        // SYSTEMS-RUN C-P2: a saved audience overrides the canned segment.
        if (is_string($campaign->segment) && str_starts_with($campaign->segment, 'audience:')) {
            $audienceId = (int) substr($campaign->segment, strlen('audience:'));
            $audience = Audience::where('store_id', $campaign->store_id)->find($audienceId);
            if ($audience) {
                $query = (new AudienceService)->forAudience($audience)
                    ->where('marketing_consent', true);
                // A saved audience replaces the query entirely, so re-apply the
                // SMS permission here too or it is silently dropped.
                if ($smsCampaign) {
                    $query->where('sms_consent', true);
                }
            }
        } elseif ($campaign->segment === 'recent') {
            $query->where(function ($q) use ($now) {
                $q->where('last_order_at', '>=', $now->copy()->subDays(30))
                    ->orWhere('created_at', '>=', $now->copy()->subDays(30));
            });
        } elseif ($campaign->segment === 'lapsed') {
            $query->where(function ($q) use ($now) {
                $q->where('last_order_at', '<', $now->copy()->subDays(30))
                    ->orWhere(function ($sub) use ($now) {
                        $sub->whereNull('last_order_at')
                            ->where('created_at', '<', $now->copy()->subDays(30));
                    });
            });
        }

        // Chunk the target list to prevent memory bloat. The build is resumable:
        // recipients already created for this campaign are skipped, and a worker that
        // died mid-build (or a sweep-stuck reset) never loses pending rows — the
        // dispatch pass below picks every pending row up again.
        //
        // BL-004: no recipient job is dispatched until EVERY chunk's rows exist. The
        // first chunk used to be dispatched while later chunks were still being
        // created, so its jobs could finish with no row pending yet and flip the
        // campaign to `sent` early.
        $query->chunk(100, function ($customers) use ($campaign) {
            $customerIds = $customers->pluck('id')->all();

            $existing = CampaignRecipient::where('campaign_id', $campaign->id)
                ->whereIn('customer_id', $customerIds)
                ->pluck('customer_id')
                ->flip();

            foreach ($customers as $customer) {
                if (isset($existing[(int) $customer->getKey()])) {
                    continue;
                }

                CampaignRecipient::create([
                    'campaign_id' => $campaign->id,
                    'customer_id' => $customer->id,
                    'status' => 'pending',
                ]);
            }
        });

        // All rows exist: queue a send job for every pending one (fresh and resumed).
        // SendSingleCampaignMessageJob claims each recipient, so a resume that queues a
        // row whose earlier job is still running (BL-002) cannot send it twice.
        CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->chunkById(200, function ($recipients) {
                foreach ($recipients as $recipient) {
                    SendSingleCampaignMessageJob::dispatch((int) $recipient->id);
                }
            });

        // No recipients are pending only when the audience was empty or every
        // recipient job already finished (sent/failed). In that case the campaign is
        // done; otherwise leave it sending — the recipient jobs finalize it as each
        // one completes.
        $hasPending = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->exists();

        if (! $hasPending) {
            $campaign->update(['status' => 'sent']);
        }
    }

    /** P6-319: a build that dies hard leaves the campaign stuck in 'sending'. */
    public function failed(\Throwable $e): void
    {
        $campaign = Campaign::find($this->campaignId);
        if ($campaign && $campaign->status === 'sending') {
            $campaign->update(['status' => 'failed']);
        }

        Log::error('Campaign send job failed: '.$e->getMessage());
    }
}
