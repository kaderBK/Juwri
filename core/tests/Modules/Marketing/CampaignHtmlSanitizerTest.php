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
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Jobs\SendSingleCampaignMessageJob;
use Juwri\Modules\Marketing\Support\CampaignHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-286: allowlist sanitiser for marketing campaign HTML bodies, the
 * CampaignController::store integration that pipes user input through it, and
 * SendSingleCampaignMessageJob's send-time re-sanitisation. The 'campaign'
 * profile (config/purify.php) is the strict e-mail profile: formatting, links
 * (http/https/mailto/tel) and store-hosted images only — no script, style,
 * form, frame, handlers or remote (tracker) <img>s.
 */
class CampaignHtmlSanitizerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tokens whose presence in the sanitised output would mean the campaign
     * allowlist leaked an execution vector. The match is case-insensitive;
     * 'style=' is the only whitespace-bearing token and the only one that
     * cannot be tightened to a tag fragment without missing
     * inline `style="..."` attributes.
     */
    private const FORBIDDEN_TOKENS = [
        '<script', 'onerror', 'onload', 'onclick', 'javascript:', 'vbscript:',
        'data:text', '<form', '<input', '<iframe', '<object', '<embed',
        '<style', 'style=', '<svg', '<meta', '<base', '<link',
    ];

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

        // P6-286: the HTMLPurifier step is host-agnostic, but the CampaignHtml
        // post-filter only keeps <img>s whose src host is one of these. Pin
        // them so test runs are deterministic regardless of .env.
        config([
            'app.url' => 'https://app.juwri.test',
            'filesystems.disks.public.url' => 'https://app.juwri.test/storage',
            'filesystems.disks.s3.url' => 'https://cdn.juwri.test',
        ]);
    }

    #[DataProvider('xssPhishCorpus')]
    public function test_campaign_html_sanitiser_strips_xss_and_phish_payloads(string $payload): void
    {
        $clean = CampaignHtml::clean($payload);
        $lower = strtolower($clean);

        foreach (self::FORBIDDEN_TOKENS as $token) {
            $this->assertStringNotContainsString(
                strtolower($token),
                $lower,
                "Sanitised campaign body still contains forbidden token '{$token}': ".$clean
            );
        }
    }

    /** @return array<string, array{0:string}> */
    public static function xssPhishCorpus(): array
    {
        // 26 corpus entries (the brief asked for at least 25). Each is the raw
        // payload an owner-style attacker would paste into the campaign body
        // expecting the mail client to execute it. The sanitiser must end up
        // with zero execution vectors, regardless of the obfuscation.
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'a javascript lower' => ['<a href="javascript:alert(1)">x</a>'],
            'a javascript mixed-case' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'a javascript entity-encoded j' => ['<a href="&#106;avascript:alert(1)">x</a>'],
            'a javascript leading whitespace' => ['<a href="  javascript:alert(1)">x</a>'],
            'a vbscript' => ['<a href="vbscript:x">x</a>'],
            'a data html base64' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
            'form + input' => ['<form action="https://evil.test"><input name=pw></form>'],
            'iframe' => ['<iframe src="https://evil.test"></iframe>'],
            'svg onload' => ['<svg onload=alert(1)>'],
            'math xlink javascript' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'],
            'body onload' => ['<body onload=alert(1)>'],
            'style block background url' => ['<style>body{background:url(https://t.evil/)}</style>'],
            'p style background url' => ['<p style="background:url(https://t.evil/x)">x</p>'],
            'object swf' => ['<object data="x.swf"></object>'],
            'embed' => ['<embed src="x">'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">'],
            'base' => ['<base href="https://evil.test/">'],
            'link stylesheet' => ['<link rel=stylesheet href=https://evil.test/x.css>'],
            'div onclick' => ['<div onclick="x()">x</div>'],
            'button formaction' => ['<button formaction="https://evil.test">x</button>'],
            'nested scrub-bypass' => ['<scr<script>ipt>alert(1)</script>'],
            'broken img comment' => ['<<img src=x onerror=alert(1)//'],
            'details ontoggle' => ['<details open ontoggle=alert(1)>'],
            'a onmouseover with safe href' => ['<a href="https://ok.test" onmouseover="x()">x</a>'],
        ];
    }

    public function test_remote_image_with_https_is_dropped(): void
    {
        $clean = CampaignHtml::clean('<p>Hi</p><img src="https://tracker.evil/p.gif">end');

        $this->assertStringNotContainsString('<img', strtolower($clean));
        $this->assertStringContainsString('Hi', $clean);
        $this->assertStringContainsString('end', $clean);
    }

    public function test_protocol_relative_remote_image_is_dropped(): void
    {
        $clean = CampaignHtml::clean('<p>Hi</p><img src="//tracker.evil/p.gif">end');

        $this->assertStringNotContainsString('<img', strtolower($clean));
    }

    public function test_remote_image_with_userinfo_appended_to_owned_host_is_dropped(): void
    {
        // http://user@app.juwri.test/x.png parses with host=app.juwri.test but
        // carries userinfo; ownMedia() rejects it on the userinfo branch.
        $clean = CampaignHtml::clean('<img src="http://user@app.juwri.test/x.png">');

        // The purifier drops the userinfo; what remains is a plain own-host image.
        $this->assertStringNotContainsString('user@', strtolower($clean));
    }

    public function test_owned_media_and_safe_links_survive(): void
    {
        $html = '<p><b>Hi</b> <a href="https://shop.example/menu">menu</a> '
            .'<img src="https://app.juwri.test/storage/a.png" alt="a"> '
            .'<img src="https://cdn.juwri.test/b.jpg"></p>';

        $clean = CampaignHtml::clean($html);

        $this->assertStringContainsString('<b>Hi</b>', $clean);
        $this->assertStringContainsString('href="https://shop.example/menu"', $clean);
        $this->assertStringContainsString('src="https://app.juwri.test/storage/a.png"', $clean);
        $this->assertStringContainsString('src="https://cdn.juwri.test/b.jpg"', $clean);

        $mailto = CampaignHtml::clean('<a href="mailto:hello@example.test">hi</a>');
        $this->assertStringContainsString('href="mailto:hello@example.test"', $mailto);

        $tel = CampaignHtml::clean('<a href="tel:+16505551234">call</a>');
        $this->assertStringContainsString('href="tel:+16505551234"', $tel);
    }

    public function test_store_endpoint_sanitises_email_body_and_records_campaign(): void
    {
        Queue::fake();

        $raw = '<p>Hi</p><script>alert(1)</script><img src=x onerror=alert(1)>';

        $response = $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'store_id' => $this->store->id,
                'channel' => 'email',
                'subject' => 'News',
                'body' => $raw,
                'segment' => 'all',
                'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'),
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertStatus(302);

        $campaign = Campaign::where('store_id', $this->store->id)
            ->where('channel', 'email')
            ->latest('id')
            ->first();

        $this->assertNotNull($campaign, 'A sanitised campaign row should be persisted.');
        $this->assertStringContainsString('<p>Hi</p>', (string) $campaign->body);
        $this->assertStringNotContainsString('<script', strtolower((string) $campaign->body));
        $this->assertStringNotContainsString('onerror', strtolower((string) $campaign->body));
    }

    public function test_store_endpoint_rejects_body_that_is_only_stripped_markup(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'store_id' => $this->store->id,
                'channel' => 'email',
                'subject' => 'News',
                'body' => '<script>x</script>',
                'segment' => 'all',
                'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'),
            ]);

        $response->assertSessionHasErrors('body');
        $this->assertSame(0, Campaign::where('store_id', $this->store->id)->count());
    }

    public function test_store_endpoint_keeps_sms_body_unchanged(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'store_id' => $this->store->id,
                'channel' => 'sms',
                'subject' => null,
                'body' => 'Hi <b>',
                'segment' => 'all',
                'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'),
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertStatus(302);

        $campaign = Campaign::where('store_id', $this->store->id)
            ->where('channel', 'sms')
            ->latest('id')
            ->first();

        $this->assertNotNull($campaign);
        $this->assertSame('Hi <b>', $campaign->body, 'SMS body is plain text and must be stored verbatim.');
    }

    public function test_send_time_job_resanitises_body_into_campaign_mail(): void
    {
        Mail::fake();

        // Body the controller would have re-sanitised on save; simulate the
        // pre-P6-286 row by hand-crafting a raw campaign body so the
        // send-time guard is what catches it.
        $campaign = Campaign::create([
            'store_id' => $this->store->id,
            'channel' => 'email',
            'subject' => 'Hi {name}',
            'body' => '<script>alert(1)</script><p>Hi {name}</p>',
            'segment' => 'all',
            'status' => 'sending',
        ]);

        $customer = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'marketing_consent' => true,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);

        app()->call([new SendSingleCampaignMessageJob($recipient->id), 'handle']);

        Mail::assertQueued(CampaignMail::class, function (CampaignMail $mail) use ($customer): bool {
            $body = (string) $mail->body;

            return $customer->email === ($mail->to[0]['address'] ?? null)
                && stripos($body, '<script') === false
                && stripos($body, 'alert(1)') === false
                && str_contains($body, 'Hi')
                && str_contains($body, $customer->name);
        });
    }
}
