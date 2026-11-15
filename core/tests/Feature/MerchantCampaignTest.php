<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Jobs\DispatchMerchantCampaign;
use App\Mails\MerchantCampaignMail;
use App\Models\MerchantCampaignSend;
use App\Services\MerchantCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Tests\TestCase;

/**
 * Admin bulk-email campaigns to the merchants.
 *
 * The contract these pin is the one the trial-reminder workflow has:
 * a campaign reaches only the segment it was given, every recipient is
 * logged, a merchant is never contacted twice for the same campaign, a
 * merchant who opted out is skipped, and the opt-out link only works with a
 * valid signature.
 */
class MerchantCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['queue.default' => 'sync']);

        $this->shareLayoutGlobals([], true);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $this->admin = User::factory()->create([
            'group_id' => '1',
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function merchant(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'm'.uniqid().'@example.com',
            'country' => 'Saudi Arabia',
            'status' => 1,
        ], $attributes));
    }

    public function test_only_platform_admins_can_access_and_send_campaigns(): void
    {
        Queue::fake();
        Mail::fake();

        $plain = $this->merchant(['user_type' => 'user']);

        $this->get(route('admin.merchants.campaign'))->assertRedirect(route('login'));
        $this->actingAs($plain)->get(route('admin.merchants.campaign'))->assertRedirect(route('login'));
        $this->actingAs($plain)->post(route('admin.merchants.campaign.send'), ['campaign' => 'nope'])->assertRedirect(route('login'));
        $this->actingAs($plain)->post(route('admin.merchants.campaign.test'), ['subject' => 'x'])->assertRedirect(route('login'));
        $this->actingAs($plain)->post(route('admin.merchants.campaign.cancel'), ['campaign' => 'nope'])->assertRedirect(route('login'));

        Queue::assertNothingPushed();
        Mail::assertNothingQueued();
    }

    public function test_the_segment_reaches_only_the_merchants_it_was_given(): void
    {
        $wanted = $this->merchant(['country' => 'Saudi Arabia']);
        $other = $this->merchant(['country' => 'Egypt']);
        $noEmail = $this->merchant(['email' => '']);
        $admin = $this->merchant(['country' => 'Saudi Arabia', 'user_type' => 'admin']);
        $disabled = $this->merchant(['country' => 'Saudi Arabia', 'status' => 0]);

        $campaign = app(MerchantCampaign::class);
        $ids = $campaign->recipients(['filters' => ['country' => 'Saudi Arabia']])->pluck('id');

        $this->assertTrue($ids->contains($wanted->id));
        $this->assertFalse($ids->contains($other->id));
        $this->assertFalse($ids->contains($noEmail->id));
        $this->assertFalse($ids->contains($admin->id));
        $this->assertFalse($ids->contains($disabled->id));
    }

    public function test_the_admin_page_shows_the_segment_counts(): void
    {
        $this->merchant(['country' => 'Saudi Arabia']);
        $this->merchant(['country' => 'Egypt']);

        $res = $this->actingAs($this->admin)->get(route('admin.merchants.campaign', [
            'country' => 'Saudi Arabia',
            'campaign' => 'test-campaign',
        ]));

        $res->assertStatus(200);
        $res->assertSee('test-campaign');
    }

    public function test_the_admin_page_wires_the_preview_button(): void
    {
        $res = $this->actingAs($this->admin)->get(route('admin.merchants.campaign', [
            'campaign' => 'wiring-check',
        ]));

        $res->assertStatus(200);
        $res->assertSee('js-preview-campaign', false);
        $res->assertSee(route('admin.merchants.campaign.preview'), false);
        $res->assertSee(route('admin.merchants.campaign.send'), false);
    }

    public function test_the_admin_page_offers_the_html_editor(): void
    {
        $res = $this->actingAs($this->admin)->get(route('admin.merchants.campaign', [
            'campaign' => 'editor-check',
        ]));

        $res->assertStatus(200);
        $res->assertSee('data-ui-rich', false);
        $res->assertSee('js-insert-shortcode', false);
        $res->assertSee('{UNSUBSCRIBE_URL}', false);
    }

    public function test_the_preview_respects_the_limit(): void
    {
        Queue::fake();
        $this->merchant();
        $this->merchant();
        $this->merchant();

        $campaign = app(MerchantCampaign::class);

        $preview = $campaign->preview([
            'filters' => ['country' => 'any'],
            'limit' => 2,
        ]);

        $this->assertSame(2, $preview['total']);
        $this->assertSame(2, $preview['to_send']);

        // and the send only reaches the capped number
        $stats = $campaign->send(['filters' => ['country' => 'any'], 'limit' => 2], 'limited', 'Subject', 'Body');

        $this->assertSame(2, $stats['sent']);
        $this->assertSame(2, MerchantCampaignSend::where('campaign', 'limited')->count());
        Queue::assertPushed(SendQueuedMailable::class, 2);
    }

    public function test_the_mail_renders_with_the_copy_and_the_unsubscribe_link(): void
    {
        $merchant = $this->merchant(['name' => 'Ali']);
        $campaign = app(MerchantCampaign::class);
        $unsubscribe = $campaign->unsubscribeUrl($merchant);

        config([
            'settings.email_sub_merchant_campaign' => 'A subject',
            'settings.email_message_merchant_campaign' => 'A body {UNSUBSCRIBE_URL}',
        ]);

        $mailable = new MerchantCampaignMail('Ali', 'Egypt', 'Free', null, null, $unsubscribe);
        $mailable->to($merchant->email);

        $rendered = $mailable->render();

        $this->assertNotEmpty($rendered);
        $this->assertStringContainsString('A body', $rendered);
        $this->assertStringContainsString('A subject', $mailable->subject);
        $this->assertStringContainsString($unsubscribe, $rendered);

        $direct = new MerchantCampaignMail('Ali', 'Egypt', 'Free', 'Other subject', 'Other body', $unsubscribe);
        $direct->to($merchant->email);

        $this->assertStringContainsString('Other body', $direct->render());
        $this->assertStringContainsString('Other subject', $direct->subject);
    }

    public function test_an_html_body_arrives_as_html_with_a_clean_text_alternative(): void
    {
        $merchant = $this->merchant(['name' => 'Ali']);
        $unsubscribe = app(MerchantCampaign::class)->unsubscribeUrl($merchant);

        $html = '<p>Hi <b>Ali</b></p><p><a href="'.$unsubscribe.'">Unsubscribe</a></p>';

        $mailable = new MerchantCampaignMail('Ali', 'Egypt', 'Free', 'Subject', $html, $unsubscribe);
        $mailable->to($merchant->email);

        $rendered = $mailable->render();
        $clean = (string) preg_replace('/ style="[^"]*"/', '', $rendered);

        $this->assertStringContainsString('<b>Ali</b>', $clean);
        $this->assertStringContainsString('<a href="'.$unsubscribe.'"', $clean);

        $mailable->build();

        $this->assertStringContainsString('Hi Ali', (string) $mailable->textView);
        $this->assertStringNotContainsString('<b>', (string) $mailable->textView);
        $this->assertStringNotContainsString('<a href', (string) $mailable->textView);
    }

    public function test_a_queued_campaign_can_be_stopped(): void
    {
        config(['queue.default' => 'database']);

        $merchant = $this->merchant();
        $queuedOnly = $this->merchant();
        $campaign = app(MerchantCampaign::class);

        $campaign->send(['filters' => ['country' => 'Saudi Arabia'], 'user_ids' => [$merchant->id]], 'stop_me', 'Subject', 'Body');

        MerchantCampaignSend::create([
            'user_id' => $queuedOnly->id,
            'campaign' => 'stop_me',
            'status' => MerchantCampaignSend::STATUS_QUEUED,
        ]);

        $this->assertSame(1, DB::table('jobs')->count(), 'the mail should be waiting in the queue');

        $result = $campaign->cancel('stop_me');

        $this->assertSame(1, $result['jobs']);
        $this->assertSame(1, $result['rows']);
        $this->assertSame(0, DB::table('jobs')->count(), 'the queued mail should be gone');
        $this->assertSame(
            MerchantCampaignSend::STATUS_SENT,
            MerchantCampaignSend::where('campaign', 'stop_me')->where('user_id', $merchant->id)->value('status')
        );
        $this->assertSame(
            MerchantCampaignSend::STATUS_STOPPED,
            MerchantCampaignSend::where('campaign', 'stop_me')->where('user_id', $queuedOnly->id)->value('status')
        );
    }

    public function test_stopping_a_campaign_marks_mails_not_yet_due_as_stopped(): void
    {
        $due = $this->merchant();
        $later = $this->merchant();
        MerchantCampaignSend::create(['user_id' => $due->id, 'campaign' => 'later_me', 'status' => MerchantCampaignSend::STATUS_SENT, 'sent_at' => now()->subMinute()]);
        MerchantCampaignSend::create(['user_id' => $later->id, 'campaign' => 'later_me', 'status' => MerchantCampaignSend::STATUS_SENT, 'sent_at' => now()->addHour()]);

        $result = app(MerchantCampaign::class)->cancel('later_me');

        $this->assertSame(1, $result['rows']);
        $this->assertSame(MerchantCampaignSend::STATUS_SENT, MerchantCampaignSend::where('user_id', $due->id)->value('status'));
        $this->assertSame(MerchantCampaignSend::STATUS_STOPPED, MerchantCampaignSend::where('user_id', $later->id)->value('status'));
    }

    public function test_stopping_one_campaign_leaves_another_one_alone(): void
    {
        Mail::fake();
        config(['queue.default' => 'database']);

        $this->merchant();
        $campaign = app(MerchantCampaign::class);

        $campaign->send(['filters' => ['country' => 'Saudi Arabia']], 'keep_me', 'Subject', 'Body');
        $campaign->send(['filters' => ['country' => 'Saudi Arabia']], 'stop_me', 'Subject', 'Body');

        $this->assertSame(2, DB::table('jobs')->count());

        $result = $campaign->cancel('stop_me');

        $this->assertSame(1, $result['jobs']);
        $this->assertSame(1, DB::table('jobs')->count(), 'the other campaign keeps its mail');
        $this->assertSame(
            MerchantCampaignSend::STATUS_SENT,
            MerchantCampaignSend::where('campaign', 'keep_me')->value('status')
        );
    }

    public function test_a_test_email_goes_to_the_operator_only(): void
    {
        Mail::fake();

        $res = $this->actingAs($this->admin)->post(route('admin.merchants.campaign.test'), [
            'subject' => 'A subject',
            'body' => '<p>A body</p>',
        ]);

        $res->assertStatus(200);
        $res->assertJson(['success' => true]);

        Mail::assertQueued(MerchantCampaignMail::class, function ($mail): bool {
            return $mail->hasTo($this->admin->email);
        });
    }

    public function test_the_campaign_page_is_in_the_admin_menu(): void
    {
        $res = $this->actingAs($this->admin)->get(route('admin.dashboard'));

        $res->assertStatus(200);
        $res->assertSee(route('admin.merchants.campaign'), false);
    }

    public function test_the_merchant_picker_finds_merchants(): void
    {
        $wanted = $this->merchant(['name' => 'Pickable Merchant', 'email' => 'pickable@example.com']);
        $other = $this->merchant(['name' => 'Someone Else', 'email' => 'other@example.com']);
        $admin = $this->merchant(['name' => 'Pickable Admin', 'email' => 'pickable-admin@example.com', 'user_type' => 'admin']);
        $disabled = $this->merchant(['name' => 'Pickable Disabled', 'email' => 'pickable-disabled@example.com', 'status' => 0]);

        $res = $this->actingAs($this->admin)->get(route('admin.merchants.campaign.merchants', [
            'q' => 'Pickable',
        ]));

        $res->assertStatus(200);
        $res->assertJsonPath('success', true);

        $ids = array_column($res->json('merchants'), 'id');
        $this->assertContains($wanted->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertNotContains($admin->id, $ids);
        $this->assertNotContains($disabled->id, $ids);
    }

    public function test_a_campaign_can_be_scheduled_for_later(): void
    {
        Mail::fake();
        config(['queue.default' => 'database']);

        $this->merchant();

        $when = now()->addHours(3);

        $res = $this->actingAs($this->admin)->post(route('admin.merchants.campaign.send'), [
            'campaign' => 'scheduled-campaign',
            'scheduled_at' => $when->format('Y-m-d\TH:i'),
        ]);

        $res->assertStatus(200);
        $res->assertJson(['success' => true]);

        $job = DB::table('jobs')->where('payload', 'like', '%DispatchMerchantCampaign%')->first();
        $this->assertNotNull($job, 'the campaign job should be waiting in the queue');
        $this->assertEqualsWithDelta(
            $when->timestamp,
            (int) $job->available_at,
            120,
            'the job should be released at the scheduled time'
        );

        $scheduled = app(MerchantCampaign::class)->scheduledCampaigns();
        $this->assertNotEmpty($scheduled);
        $this->assertSame('scheduled-campaign', $scheduled[0]['campaign']);
    }

    public function test_a_scheduled_campaign_can_be_stopped_before_it_fires(): void
    {
        Mail::fake();
        config(['queue.default' => 'database']);

        $this->merchant();

        $this->actingAs($this->admin)->post(route('admin.merchants.campaign.send'), [
            'campaign' => 'cancel_scheduled',
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%DispatchMerchantCampaign%')->count());

        $res = $this->actingAs($this->admin)->post(route('admin.merchants.campaign.cancel'), [
            'campaign' => 'cancel_scheduled',
        ]);

        $res->assertStatus(200);
        $res->assertJson(['success' => true, 'stopped_scheduled' => 1]);
        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%DispatchMerchantCampaign%')->count());
        $this->assertSame([], app(MerchantCampaign::class)->scheduledCampaigns());
    }

    public function test_the_send_action_queues_a_campaign_and_refuses_an_empty_segment(): void
    {
        Queue::fake([SendQueuedMailable::class]);

        $empty = $this->actingAs($this->admin)->post(route('admin.merchants.campaign.send'), [
            'campaign' => 'post-empty',
            'user_ids' => '999999999',
        ]);
        $empty->assertStatus(422);
        $this->assertSame(0, MerchantCampaignSend::count());

        $merchant = $this->merchant();

        $res = $this->actingAs($this->admin)->post(route('admin.merchants.campaign.send'), [
            'campaign' => 'post-send',
            'subject' => 'Subject',
            'body' => 'Body',
            'per_minute' => 30,
        ]);

        $res->assertStatus(200);
        $res->assertJson(['success' => true, 'campaign' => 'post-send']);

        $expectedCount = User::query()
            ->where('user_type', 'user')
            ->where('status', true)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->count();

        $logged = MerchantCampaignSend::where('campaign', 'post-send')->get();
        $this->assertTrue($logged->contains('user_id', $merchant->id));
        $this->assertSame($expectedCount, $logged->count());
        $this->assertSame(['sent'], $logged->pluck('status')->unique()->values()->all());
        Queue::assertPushed(SendQueuedMailable::class, $expectedCount);
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        Queue::fake();
        $this->merchant();

        $stats = app(MerchantCampaign::class)->send(
            ['filters' => ['country' => 'Saudi Arabia']],
            'dry-run-campaign',
            'Subject',
            'Body',
            true
        );

        $this->assertSame(1, $stats['sent']);
        $this->assertSame(0, MerchantCampaignSend::count());
        Queue::assertNothingPushed();
    }

    public function test_a_send_logs_every_recipient_and_is_idempotent(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $campaign = app(MerchantCampaign::class);

        $stats = $campaign->send(['filters' => ['country' => 'Saudi Arabia']], 'idem-campaign', 'Subject', 'Body');

        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, MerchantCampaignSend::where('campaign', 'idem-campaign')->count());
        $this->assertSame(
            MerchantCampaignSend::STATUS_SENT,
            MerchantCampaignSend::where('user_id', $merchant->id)->value('status')
        );

        $again = $campaign->send(['filters' => ['country' => 'Saudi Arabia']], 'idem-campaign', 'Subject', 'Body');

        $this->assertSame(0, $again['sent']);
        $this->assertSame(1, $again['skipped_duplicate']);
        $this->assertSame(1, MerchantCampaignSend::where('campaign', 'idem-campaign')->count());
        Queue::assertPushed(SendQueuedMailable::class, 1);
    }

    public function test_a_merchant_who_opted_out_is_skipped(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        UserOption::updateUserOption($merchant->id, MerchantCampaign::OPT_OUT_KEY, '0');

        $stats = app(MerchantCampaign::class)->send(
            ['filters' => ['country' => 'Saudi Arabia']],
            'optout-campaign',
            'Subject',
            'Body'
        );

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['skipped_optout']);
        $this->assertSame(
            MerchantCampaignSend::STATUS_SKIPPED_OPTOUT,
            MerchantCampaignSend::where('user_id', $merchant->id)->value('status')
        );
        Queue::assertNothingPushed();
    }

    public function test_the_opt_out_link_needs_a_valid_signature(): void
    {
        $merchant = $this->merchant();
        $signedUrl = URL::signedRoute('campaigns.merchant.unsubscribe', ['user' => $merchant->id]);

        $this->get('/campaigns/merchant/unsubscribe/'.$merchant->id)->assertStatus(403);
        $this->post('/campaigns/merchant/unsubscribe/'.$merchant->id)->assertStatus(403);

        // GET only shows the confirmation page and does not change state (mail scanners click links)
        $this->get($signedUrl)
            ->assertStatus(200)
            ->assertSee('Yes, unsubscribe me');

        $this->assertNotSame('0', user_options($merchant->id, MerchantCampaign::OPT_OUT_KEY));

        // Signed POST records the opt-out and is idempotent (including one-click POST)
        $this->post($signedUrl, ['List-Unsubscribe' => 'One-Click'])
            ->assertStatus(200)
            ->assertSee('You are unsubscribed');

        $this->assertSame('0', user_options($merchant->id, MerchantCampaign::OPT_OUT_KEY));

        $this->post($signedUrl)
            ->assertStatus(200)
            ->assertSee('You are unsubscribed');
    }

    public function test_the_mail_includes_one_click_unsubscribe_headers(): void
    {
        config(['mail.default' => 'array']);
        Mail::purge('array');

        $merchant = $this->merchant();
        $unsubscribe = app(MerchantCampaign::class)->unsubscribeUrl($merchant);

        $mailable = new MerchantCampaignMail('Ali', 'Egypt', 'Free', 'Subject', 'Body', $unsubscribe);
        Mail::to($merchant->email)->send($mailable);

        /** @var \Illuminate\Mail\Transport\ArrayTransport $transport */
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $sent = $transport->messages()->first();
        $this->assertNotNull($sent);

        /** @var \Symfony\Component\Mime\Email $message */
        $message = $sent->getOriginalMessage();
        $headers = $message->getHeaders();

        $this->assertSame('<'.$unsubscribe.'>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());
    }

    public function test_the_campaign_job_carries_the_segment(): void
    {
        Queue::fake([SendQueuedMailable::class]);
        $merchant = $this->merchant();

        $job = new DispatchMerchantCampaign(
            ['filters' => ['country' => 'Saudi Arabia']],
            'job-campaign',
            'Subject',
            'Body'
        );

        $this->assertSame('job-campaign', $job->uniqueId());
        $this->assertGreaterThan(0, $job->timeout);

        dispatch($job);
        dispatch(new DispatchMerchantCampaign(
            ['filters' => ['country' => 'Saudi Arabia']],
            'job-campaign',
            'Subject',
            'Body'
        ));

        $this->assertSame(1, MerchantCampaignSend::where('campaign', 'job-campaign')->count());
        $this->assertTrue(MerchantCampaignSend::where('user_id', $merchant->id)->exists());
        Queue::assertPushed(SendQueuedMailable::class, 1);
    }

    public function test_the_artisan_command_supports_dry_run_and_send(): void
    {
        Queue::fake();
        $this->merchant(['country' => 'Saudi Arabia']);

        $this->artisan('merchants:campaign', [
            'campaign' => 'cli-dry',
            '--country' => 'Saudi Arabia',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, MerchantCampaignSend::count());
        Queue::assertNothingPushed();

        $this->artisan('merchants:campaign', [
            'campaign' => 'cli-live',
            '--country' => 'Saudi Arabia',
            '--subject' => 'CLI Subject',
            '--body' => 'CLI Body',
        ])->assertSuccessful();

        $this->assertSame(1, MerchantCampaignSend::where('campaign', 'cli-live')->count());
        Queue::assertPushed(SendQueuedMailable::class, 1);
    }

    public function test_the_segment_sql_applies_the_table_prefix_exactly_once(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('subscriptions table is provided by the Billing package.');
        }

        $connection = DB::connection();
        $original = $connection->getTablePrefix();
        $connection->setTablePrefix('juwri_');

        try {
            Schema::create('subscriptions', function ($table): void {
                $table->id();
            });

            $campaign = app(MerchantCampaign::class);

            $builder = $campaign->recipients([
                'filters' => [
                    'country' => 'Saudi Arabia',
                    'plan' => '3',
                    'trial' => 'ending',
                    'trial_within' => 7,
                    'has_subscription' => '1',
                ],
            ]);

            $sql = $builder->toSql();

            $this->assertStringNotContainsString('juwri_juwri_', $sql);
            $this->assertStringContainsString('juwri_users', $sql);
            $this->assertStringContainsString('juwri_subscriptions', $sql);
            $this->assertStringContainsString('FROM juwri_subscriptions', $sql);
        } finally {
            Schema::dropIfExists('subscriptions');
            $connection->setTablePrefix($original);
        }
    }
}
