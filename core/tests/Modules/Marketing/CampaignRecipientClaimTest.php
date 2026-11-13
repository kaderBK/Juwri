<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Mails\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Jobs\SendCampaignJob;
use Juwri\Modules\Marketing\Jobs\SendSingleCampaignMessageJob;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\TestCase;

class CampaignRecipientClaimTest extends TestCase
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

    private function recipient(Campaign $campaign, Customer $customer): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
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

    public function test_recipient_claim_blocks_a_second_worker_until_released(): void
    {
        Mail::fake();

        $campaign = $this->campaign(['status' => 'sending']);
        $customer = $this->customer();
        $recipient = $this->recipient($campaign, $customer);

        $lock = Cache::lock('campaign-recipient:'.$recipient->id, 60);
        $this->assertTrue($lock->get());

        (new SendSingleCampaignMessageJob($recipient->id))->handle($this->sms());

        // CampaignMail implements ShouldQueue, so the fake records it as queued
        // rather than sent; assert on the queue side to prove nothing went out.
        Mail::assertNothingQueued();
        $this->assertSame('pending', $recipient->fresh()->status);
        $this->assertSame(0, (int) $campaign->fresh()->sent_count);

        $lock->release();

        (new SendSingleCampaignMessageJob($recipient->id))->handle($this->sms());

        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame(1, (int) $campaign->fresh()->sent_count);
        Mail::assertQueued(CampaignMail::class, 1);
    }

    public function test_recipient_job_is_idempotent(): void
    {
        Mail::fake();

        $campaign = $this->campaign(['status' => 'sending']);
        $customer = $this->customer();
        $recipient = $this->recipient($campaign, $customer);

        (new SendSingleCampaignMessageJob($recipient->id))->handle($this->sms());
        (new SendSingleCampaignMessageJob($recipient->id))->handle($this->sms());

        Mail::assertQueued(CampaignMail::class, 1);
        $this->assertSame(1, (int) $campaign->fresh()->sent_count);
        $this->assertSame('sent', $recipient->fresh()->status);
    }

    public function test_recipient_claim_is_released_after_a_transient_failure(): void
    {
        $campaign = $this->campaign(['status' => 'sending']);
        $customer = $this->customer();
        $recipient = $this->recipient($campaign, $customer);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        try {
            (new SendSingleCampaignMessageJob($recipient->id))->handle($this->sms());
            $this->fail('expected the transient failure to rethrow');
        } catch (\RuntimeException $e) {
            $this->assertSame('smtp down', $e->getMessage());
        }

        $this->assertSame('pending', $recipient->fresh()->status);

        $lock = Cache::lock('campaign-recipient:'.$recipient->id, 5);
        $this->assertTrue($lock->get(), 'the claim must be released when the job throws');
        $lock->release();
    }

    public function test_resume_while_a_recipient_job_is_still_running_sends_each_recipient_once(): void
    {
        Mail::fake();
        Queue::fake();

        $campaign = $this->campaign();
        for ($i = 0; $i < 3; $i++) {
            $this->customer(['name' => "Diner {$i}", 'email' => "diner{$i}@example.com"]);
        }

        (new SendCampaignJob($campaign->id))->handle();

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)->orderBy('id')->get();
        $this->assertCount(3, $recipients);

        // The first worker's job is still "running": its recipient claim is held.
        $held = $recipients->first();
        $lock = Cache::lock('campaign-recipient:'.$held->id, 60);
        $this->assertTrue($lock->get());

        Campaign::whereKey($campaign->id)->update(['status' => 'scheduled']);
        (new SendCampaignJob($campaign->id))->handle();

        $jobs = Queue::pushed(SendSingleCampaignMessageJob::class);
        $this->assertCount(6, $jobs, 'both runs dispatch one job for each of the three pending rows');

        // First pass: the held recipient loses the claim, everyone else sends.
        foreach ($jobs as $job) {
            $job->handle($this->sms());
        }

        $lock->release();

        // Second pass: the previously held row sends once; the rest are idempotent.
        foreach ($jobs as $job) {
            $job->handle($this->sms());
        }

        Mail::assertQueued(CampaignMail::class, 3);
        $this->assertSame(3, (int) $campaign->fresh()->sent_count);
        $this->assertSame('sent', $campaign->fresh()->status);
        $this->assertSame(3, CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'sent')->count());
    }

    public function test_all_recipient_rows_exist_before_the_first_send(): void
    {
        config(['queue.default' => 'sync']);
        Mail::fake();

        $campaign = $this->campaign();
        for ($i = 0; $i < 250; $i++) {
            $this->customer(['name' => "Diner {$i}", 'email' => "diner{$i}@example.com"]);
        }

        // BL-004: recorded if the campaign is ever persisted through a model update.
        // The guarded finalize uses a query-builder update, so this normally stays
        // null; the recipient event below is the load-bearing assertion.
        $rowsWhenSent = null;
        Campaign::updating(function (Campaign $updating) use (&$rowsWhenSent) {
            if ($updating->isDirty('status') && $updating->status === 'sent') {
                $rowsWhenSent = CampaignRecipient::where('campaign_id', $updating->id)->count();
            }
        });

        // Fires when the first recipient is marked sent (Mail::fake queues
        // CampaignMail without raising MessageSending), i.e. at the first send.
        $rowsAtFirstRecipientUpdate = null;
        Event::listen('eloquent.updated: '.CampaignRecipient::class, function (CampaignRecipient $recipient) use (&$rowsAtFirstRecipientUpdate) {
            if ($rowsAtFirstRecipientUpdate === null) {
                $rowsAtFirstRecipientUpdate = CampaignRecipient::where('campaign_id', $recipient->campaign_id)->count();
            }
        });

        (new SendCampaignJob($campaign->id))->handle();

        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(250, (int) $campaign->sent_count);
        $this->assertSame(250, CampaignRecipient::where('campaign_id', $campaign->id)->count());
        $this->assertSame(250, $rowsAtFirstRecipientUpdate, 'every recipient row must exist before the first recipient is sent');

        if ($rowsWhenSent !== null) {
            $this->assertSame(250, $rowsWhenSent);
        }

        Mail::assertQueued(CampaignMail::class, 250);
    }
}
