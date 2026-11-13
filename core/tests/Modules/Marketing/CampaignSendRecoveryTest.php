<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Jobs\SendCampaignJob;
use Juwri\Modules\Marketing\Jobs\SendSingleCampaignMessageJob;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\TestCase;

class CampaignSendRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->store = Store::create([
            'id' => 101,
            'user_id' => $this->user->id,
            'title' => 'Delicious Bites',
            'slug' => 'delicious-bites',
        ]);
    }

    private function campaign(array $overrides = []): Campaign
    {
        return Campaign::create(array_merge([
            'store_id' => $this->store->id,
            'channel' => 'email',
            'subject' => 'Promo {name}!',
            'body' => 'Save 10%',
            'segment' => 'all',
            'status' => 'scheduled',
        ], $overrides));
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $this->store->id,
            'name' => 'Diner',
            'email' => 'diner@example.com',
            'marketing_consent' => true,
        ], $overrides));
    }

    private function sms(): Sms
    {
        return new class implements Sms
        {
            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                return true;
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        };
    }

    public function test_recipient_jobs_dispatch_per_chunk_and_campaign_finishes_sent_after_last_job(): void
    {
        Queue::fake();
        Mail::fake();

        $campaign = $this->campaign();
        for ($i = 0; $i < 150; $i++) {
            $this->customer(['email' => "diner{$i}@example.com", 'name' => "Diner {$i}"]);
        }

        (new SendCampaignJob($campaign->id))->handle();

        // Every recipient got a job (dispatched per chunk, not after the whole loop).
        Queue::assertPushed(SendSingleCampaignMessageJob::class, 150);
        $this->assertSame('sending', $campaign->fresh()->status, 'campaign must stay sending while recipients are pending');

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)->orderBy('id')->get();
        foreach ($recipients->slice(0, 149) as $recipient) {
            (new SendSingleCampaignMessageJob($recipient->id))->handle($this->sms());
        }
        $this->assertSame('sending', $campaign->fresh()->status, 'not sent until the last pending recipient is done');

        (new SendSingleCampaignMessageJob($recipients->last()->id))->handle($this->sms());

        $this->assertSame('sent', $campaign->fresh()->status);
        $this->assertSame(150, (int) $campaign->fresh()->sent_count);
    }

    public function test_campaign_with_pending_recipient_is_not_marked_sent(): void
    {
        Queue::fake();

        $this->customer();
        $campaign = $this->campaign();

        (new SendCampaignJob($campaign->id))->handle();

        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count());
    }

    public function test_resume_does_not_create_duplicate_recipient_rows(): void
    {
        Queue::fake();

        $this->customer();
        $campaign = $this->campaign();

        (new SendCampaignJob($campaign->id))->handle();
        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->count());

        // Sweeper resets the stuck build; re-running must skip existing rows.
        Campaign::whereKey($campaign->id)->update(['status' => 'scheduled']);
        (new SendCampaignJob($campaign->id))->handle();

        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->count());
        // The pending row is re-dispatched on resume (one job per run, no duplicates).
        Queue::assertPushed(SendSingleCampaignMessageJob::class, 2);
    }

    public function test_transient_mail_failure_rethrows_then_final_attempt_marks_failed_once(): void
    {
        $customer = $this->customer();
        $campaign = $this->campaign(['status' => 'sending']);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        // Attempt 1 (tries=2): transient transport failure rethrows so the queue
        // retries with backoff; the recipient stays pending.
        try {
            (new SendSingleCampaignMessageJob($recipient->id))->handle($this->sms());
            $this->fail('expected the transient failure to rethrow');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame('pending', $recipient->fresh()->status);
        $this->assertSame(0, (int) $campaign->fresh()->failed_count);

        // Final attempt (tries=1): give up, mark failed and increment exactly once.
        $job = new SendSingleCampaignMessageJob($recipient->id);
        $job->tries = 1;
        $job->handle($this->sms());

        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertSame(1, (int) $campaign->fresh()->failed_count);
        $this->assertSame('sent', $campaign->fresh()->status, 'no pending recipients remain');
    }

    public function test_send_campaign_job_failed_hook_marks_campaign_failed(): void
    {
        $campaign = $this->campaign(['status' => 'sending']);
        Log::spy();

        (new SendCampaignJob($campaign->id))->failed(new \RuntimeException('boom'));

        $this->assertSame('failed', $campaign->fresh()->status);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_sweeper_resets_stuck_campaigns_and_ignores_fresh_ones(): void
    {
        Queue::fake();

        $stuck = $this->campaign(['status' => 'sending']);
        $fresh = $this->campaign(['status' => 'sending']);
        DB::table('campaigns')->where('id', $stuck->id)->update(['updated_at' => now()->subMinutes(31)]);

        $this->artisan('campaigns:sweep-stuck')->assertSuccessful();

        $this->assertSame('scheduled', $stuck->fresh()->status);
        $this->assertSame('sending', $fresh->fresh()->status);
        Queue::assertPushed(SendCampaignJob::class, 1);
    }
}
