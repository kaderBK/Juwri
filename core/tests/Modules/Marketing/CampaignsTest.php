<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Mails\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Jobs\SendCampaignJob;
use Juwri\Modules\Marketing\Jobs\SendSingleCampaignMessageJob;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Models\EmailSuppression;
use Tests\TestCase;

class CampaignsTest extends TestCase
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

    public function test_can_create_and_schedule_campaign()
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'store_id' => $this->store->id,
                'channel' => 'email',
                'subject' => 'Hello {name}!',
                'body' => 'Check out our new menu items.',
                'segment' => 'all',
                'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'),
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertStatus(302);
        $this->assertDatabaseHas('campaigns', [
            'store_id' => $this->store->id,
            'channel' => 'email',
            'subject' => 'Hello {name}!',
            'segment' => 'all',
            'status' => 'scheduled',
        ]);

        Queue::assertPushed(SendCampaignJob::class);
    }

    public function test_audience_segmentation_excludes_non_consented()
    {
        // Consented customer
        $consented = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Consented Diner',
            'email' => 'consented@example.com',
            'marketing_consent' => true,
        ]);

        // Non-consented customer
        $nonConsented = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Non-Consented Diner',
            'email' => 'noconsent@example.com',
            'marketing_consent' => false,
        ]);

        $campaign = Campaign::create([
            'store_id' => $this->store->id,
            'channel' => 'email',
            'subject' => 'Promo!',
            'body' => 'Save 10%',
            'segment' => 'all',
            'status' => 'scheduled',
        ]);

        Queue::fake();

        // Run the SendCampaignJob directly
        new SendCampaignJob($campaign->id)->handle();

        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_id' => $campaign->id,
            'customer_id' => $consented->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('campaign_recipients', [
            'campaign_id' => $campaign->id,
            'customer_id' => $nonConsented->id,
        ]);

        Queue::assertPushed(SendSingleCampaignMessageJob::class);
    }

    public function test_unsubscribe_endpoint_revokes_consent()
    {
        $customer = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Unsubscribe Test',
            'email' => 'unsub@example.com',
            'marketing_consent' => true,
        ]);

        $url = URL::temporarySignedRoute(
            'campaigns.unsubscribe',
            now()->addMinutes(30),
            ['customer' => $customer->id]
        );

        // P6-222: GET only shows the confirm page; the POST withdraws consent.
        $this->get($url)->assertStatus(200)->assertSee('<form method="POST"', false);
        $this->assertTrue($customer->fresh()->marketing_consent, 'GET (mail scanners) must not change consent');

        $this->post($url)->assertStatus(200);
        $customer->refresh();
        $this->assertFalse($customer->marketing_consent);
    }

    public function test_p6_222_expired_old_link_still_unsubscribes_and_new_link_has_no_expiry()
    {
        $customer = Customer::create([
            'store_id' => $this->store->id, 'name' => 'Old', 'email' => 'old@example.com', 'marketing_consent' => true,
        ]);
        $old = URL::temporarySignedRoute('campaigns.unsubscribe', now()->addDays(30), ['customer' => $customer->id]);
        $this->travel(400)->days();
        $this->get($old)->assertStatus(200);
        $this->post($old)->assertStatus(200);
        $this->assertFalse($customer->fresh()->marketing_consent);

        $new = CampaignMail::unsubscribeUrlFor($customer->id);
        $this->assertStringNotContainsString('expires=', $new);
        $this->post(str_replace('signature=', 'signature=0', $new))->assertStatus(401);
    }

    public function test_p6_222_one_click_post_without_session_or_csrf_token()
    {
        $customer = Customer::create([
            'store_id' => $this->store->id, 'name' => 'One', 'email' => 'one@example.com', 'marketing_consent' => true, 'sms_consent' => true,
        ]);
        $url = CampaignMail::unsubscribeUrlFor($customer->id);
        // RFC 8058: the provider POSTs "List-Unsubscribe=One-Click" with no cookies.
        $this->withMiddleware()->call('POST', $url, ['List-Unsubscribe' => 'One-Click'])->assertStatus(200);
        $this->assertFalse($customer->fresh()->marketing_consent);
        $this->assertFalse($customer->fresh()->sms_consent);
    }

    public function test_p6_222_campaign_mail_has_list_unsubscribe_headers()
    {
        $mail = new CampaignMail('Hi', 'Body', 'https://example.test/campaigns/unsubscribe/5?signature=abc');
        $h = $mail->headers()->text;
        $this->assertSame('<https://example.test/campaigns/unsubscribe/5?signature=abc>', $h['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $h['List-Unsubscribe-Post']);

        $sent = (new CampaignMail('Hi', 'Body', 'https://example.test/u?signature=abc'))->render();
        $this->assertNotEmpty($sent);
    }

    public function test_unsubscribe_link_withdraws_sms_consent_too()
    {
        $customer = Customer::create([
            'store_id' => $this->store->id, 'name' => 'Both', 'email' => 'both@example.com', 'phone' => '+16505550199',
            'marketing_consent' => true, 'sms_consent' => true, 'consent_at' => now(),
        ]);
        $url = URL::temporarySignedRoute('campaigns.unsubscribe', now()->addMinutes(30), ['customer' => $customer->id]);

        $this->post($url)->assertStatus(200);

        $customer->refresh();
        $this->assertFalse($customer->marketing_consent);
        $this->assertFalse($customer->sms_consent, 'a later checkout ticking only the marketing box must not bring SMS back');

        // a link re-pointed at another customer is refused
        $other = Customer::create(['store_id' => $this->store->id, 'name' => 'Other', 'email' => 'o@example.com', 'marketing_consent' => true]);
        $this->post(str_replace('/unsubscribe/'.$customer->id.'?', '/unsubscribe/'.$other->id.'?', $url))->assertStatus(401);
        $this->assertTrue($other->fresh()->marketing_consent);
    }

    public function test_an_sms_is_not_sent_when_sms_consent_was_withdrawn_after_the_list_was_built()
    {
        $customer = Customer::create([
            'store_id' => $this->store->id, 'name' => 'Late', 'phone' => '+16505550198',
            'marketing_consent' => true, 'sms_consent' => false,
        ]);
        $campaign = Campaign::create([
            'store_id' => $this->store->id, 'channel' => 'sms', 'body' => 'Offer', 'segment' => 'all', 'status' => 'sent',
        ]);
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'pending']);
        $sms = \Mockery::mock(Sms::class);
        $sms->shouldNotReceive('send');

        (new SendSingleCampaignMessageJob($recipient->id))->handle($sms);

        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertSame('No SMS consent', $recipient->fresh()->error_message);
        $this->assertSame(1, (int) $campaign->fresh()->failed_count);
    }

    /** P6-249: a hard-bounced / complained address is never mailed by a campaign. */
    public function test_a_campaign_email_skips_a_suppressed_address()
    {
        Mail::fake();
        EmailSuppression::record('Dead@Example.com', 'bounce', 'mailgun');
        $customer = Customer::create(['store_id' => $this->store->id, 'name' => 'Dead', 'email' => 'dead@example.com', 'marketing_consent' => true]);
        $campaign = Campaign::create([
            'store_id' => $this->store->id, 'channel' => 'email', 'subject' => 'Hi', 'body' => 'Offer', 'segment' => 'all', 'status' => 'sent',
        ]);
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'pending']);

        (new SendSingleCampaignMessageJob($recipient->id))->handle(\Mockery::mock(Sms::class));

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertSame('Address hard-bounced', $recipient->fresh()->error_message);
    }
}
