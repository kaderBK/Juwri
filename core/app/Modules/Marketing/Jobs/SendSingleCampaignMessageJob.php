<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Jobs;

use App\Mails\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Modules\Marketing\Support\CampaignHtml;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Models\EmailSuppression;

class SendSingleCampaignMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public array $backoff = [60, 300];

    public function __construct(public int $recipientId) {}

    /** BL-002: seconds a recipient claim lives (a hard-killed worker frees it after this). */
    private const CLAIM_SECONDS = 300;

    public function handle(Sms $smsService): void
    {
        // BL-002: one worker at a time per recipient. A campaign resume/sweep re-queues pending rows, which can
        // land while the first job for the same row is still sending; the loser stops here. Released as soon as
        // the job ends (including a thrown retry), so the queue's own retry can claim it again.
        $claim = Cache::lock('campaign-recipient:'.$this->recipientId, self::CLAIM_SECONDS);
        if (! $claim->get()) {
            return;
        }

        try {
            $this->send($smsService);
        } finally {
            $claim->release();
        }
    }

    private function send(Sms $smsService): void
    {
        $recipient = CampaignRecipient::with(['campaign', 'customer'])->find($this->recipientId);
        if (! $recipient || ! $recipient->campaign || ! $recipient->customer) {
            return;
        }

        $campaign = $recipient->campaign;
        $customer = $recipient->customer;

        // Idempotency: a worker killed after send() but before the status update
        // replays this job ($tries=2) — don't re-send or double-count.
        if ($recipient->status === 'sent') {
            $this->finalize($campaign);

            return;
        }

        // Double safeguard for consent
        if (! $customer->marketing_consent) {
            $this->markFailed($recipient, $campaign, 'No marketing consent');

            return;
        }

        // P4-10: SMS needs its own, narrower consent at send time too (it can be withdrawn after the recipient list was built).
        if ($campaign->channel === 'sms' && ! $customer->sms_consent) {
            $this->markFailed($recipient, $campaign, 'No SMS consent');

            return;
        }

        // Replace placeholders.
        $name = $customer->name ?: 'Customer';

        // P6-287: the merged name lands inside HTML mail, so escape it for the email
        // body. The subject is a plain-text header: leave it unescaped but strip any
        // tags, otherwise HTML entities would show up literally in the client's subject.
        $subjectName = $name;
        $bodyName = $name;
        if ($campaign->channel === 'email') {
            $subjectName = strip_tags($name);
            $bodyName = e($name);
        }

        $subject = str_replace('{name}', $subjectName, $campaign->subject ?? '');
        // P6-286: e-mail bodies are re-sanitised at send time (rows saved before the save-time
        // sanitiser were stored raw). SMS is plain text and is left as typed. The escaped name is
        // merged after cleaning so the sanitiser never sees (or un-escapes) customer input.
        $template = $campaign->channel === 'email' ? CampaignHtml::clean((string) $campaign->body) : (string) $campaign->body;
        $body = str_replace('{name}', $bodyName, $template);

        // Generate dynamic signed unsubscribe link
        // P6-222: no expiry, so the link keeps working for as long as the mail exists.
        $unsubscribeUrl = CampaignMail::unsubscribeUrlFor((int) $customer->id);

        if ($campaign->channel === 'email') {
            if (empty($customer->email)) {
                $this->markFailed($recipient, $campaign, 'Customer email is empty');

                return;
            }

            // P6-249: never mail an address the provider reported as hard-bounced or as a spam complaint.
            if ($reason = EmailSuppression::reasonFor($customer->email)) {
                $this->markFailed($recipient, $campaign, $reason === EmailSuppression::COMPLAINT ? 'Address complained (spam report)' : 'Address hard-bounced');

                return;
            }

            try {
                Mail::to($customer->email)->send(new CampaignMail($subject, $body, $unsubscribeUrl));
                $this->markSent($recipient, $campaign);
            } catch (\Exception $e) {
                Log::error('Failed to send campaign email: '.$e->getMessage());

                // P6-319: transient transport failure — ask the queue to retry with
                // backoff instead of burning the recipient as failed on the first try.
                if ($this->attempts() < $this->tries) {
                    throw $e;
                }

                $this->markFailed($recipient, $campaign, $e->getMessage());
            }
        } elseif ($campaign->channel === 'sms') {
            if (empty($customer->phone)) {
                $this->markFailed($recipient, $campaign, 'Customer phone is empty');

                return;
            }

            // SMS compliance: STOP keyword opt-out instruction.
            $smsBody = $body."\n\n".___('Reply STOP to unsubscribe.');

            try {
                // marketing=true -> SmsService suppresses opted-out numbers.
                $sent = $smsService->send($customer->phone, $smsBody, $campaign->store_id, 'campaign', true);
                if ($sent) {
                    $this->markSent($recipient, $campaign);
                } else {
                    // A false return is a definitive (non-retryable) delivery failure.
                    $this->markFailed($recipient, $campaign, 'SMS Service failed to deliver message.');
                }
            } catch (\Exception $e) {
                Log::error('Failed to send campaign SMS: '.$e->getMessage());

                if ($this->attempts() < $this->tries) {
                    throw $e;
                }

                $this->markFailed($recipient, $campaign, $e->getMessage());
            }
        }
    }

    /** P6-319: hard failure (timeout/SIGKILL/OOM) that skips handle()'s catches. */
    public function failed(\Throwable $e): void
    {
        $recipient = CampaignRecipient::find($this->recipientId);
        if (! $recipient) {
            return;
        }

        // No double counting: a recipient already sent or failed keeps its status.
        if ($recipient->status === 'pending') {
            $recipient->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Campaign::whereKey($recipient->campaign_id)->increment('failed_count');
        }

        $campaign = Campaign::find($recipient->campaign_id);
        if ($campaign) {
            $this->finalize($campaign);
        }
    }

    private function markSent(CampaignRecipient $recipient, Campaign $campaign): void
    {
        $recipient->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $campaign->increment('sent_count');
        $this->finalize($campaign);
    }

    private function markFailed(CampaignRecipient $recipient, Campaign $campaign, string $message): void
    {
        $recipient->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);
        $campaign->increment('failed_count');
        $this->finalize($campaign);
    }

    /**
     * Flip a sending campaign to sent once no recipient is still pending. A single
     * guarded UPDATE (status=sending AND no pending rows) makes the transition
     * race-safe across concurrently finishing recipient jobs.
     */
    private function finalize(Campaign $campaign): void
    {
        Campaign::whereKey($campaign->id)
            ->where('status', 'sending')
            ->whereNotIn('id', CampaignRecipient::where('status', 'pending')->select('campaign_id'))
            ->update(['status' => 'sent']);
    }
}
