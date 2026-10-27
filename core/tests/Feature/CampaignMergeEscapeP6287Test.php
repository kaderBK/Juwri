<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Mails\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Jobs\SendSingleCampaignMessageJob;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\TestCase;

/**
 * P6-287: the {name} merge must never put a raw customer name into HTML mail, and
 * SMS bodies are capped at 612 characters.
 */
class CampaignMergeEscapeP6287Test extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->user = User::factory()->create();
        $this->store = Store::create([
            'id' => 101,
            'user_id' => $this->user->id,
            'title' => 'Delicious Bites',
            'slug' => 'delicious-bites',
        ]);
    }

    public function test_customer_name_is_escaped_in_email_body_and_stripped_in_subject(): void
    {
        $customer = Customer::create([
            'store_id' => $this->store->id,
            'name' => '<b>x</b>',
            'email' => 'escaped@example.com',
            'marketing_consent' => true,
        ]);

        $campaign = Campaign::create([
            'store_id' => $this->store->id,
            'channel' => 'email',
            'subject' => 'Hello {name}!',
            'body' => 'Hi {name}, welcome.',
            'segment' => 'all',
            'status' => 'scheduled',
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);

        Mail::fake();
        $sms = \Mockery::mock(Sms::class);

        (new SendSingleCampaignMessageJob($recipient->id))->handle($sms);

        // CampaignMail implements ShouldQueue, so the fake records it as queued.
        Mail::assertQueued(CampaignMail::class, function (CampaignMail $mail) {
            $this->assertSame('Hello x!', $mail->subject);

            $html = $mail->render();
            $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
            $this->assertStringNotContainsString('<b>x</b>', $html);

            return true;
        });
    }

    public function test_sms_body_of_613_characters_is_rejected(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'store_id' => $this->store->id,
                'channel' => 'sms',
                'body' => str_repeat('a', 613),
                'segment' => 'all',
            ]);

        $response->assertSessionHasErrors('body');
    }

    public function test_sms_body_of_612_characters_is_accepted(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'store_id' => $this->store->id,
                'channel' => 'sms',
                'body' => str_repeat('a', 612),
                'segment' => 'all',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('campaigns', [
            'store_id' => $this->store->id,
            'channel' => 'sms',
            'segment' => 'all',
        ]);
    }
}
