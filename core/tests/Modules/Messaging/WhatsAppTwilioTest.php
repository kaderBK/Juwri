<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\TwilioProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderException;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTwilioTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): TwilioProvider
    {
        return new TwilioProvider('twilio', [
            'sid' => 'AC123', 'auth_token' => 'tok', 'from_number' => '+15550000000',
        ]);
    }

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        parent::tearDown();
    }

    public function test_is_configured_requires_all_secrets(): void
    {
        $this->assertTrue($this->driver()->isConfigured());
        $this->assertFalse(new TwilioProvider('twilio', ['sid' => 'AC1'])->isConfigured());
    }

    public function test_send_text_posts_whatsapp_prefixed_numbers(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM99', 'status' => 'queued'], 201)]);

        $res = $this->driver()->sendText('+15551234567', 'hello');

        $this->assertTrue($res->sent);
        $this->assertSame('SM99', $res->providerMsgId);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'Accounts/AC123/Messages.json')
                && $req['To'] === 'whatsapp:+15551234567'
                && $req['From'] === 'whatsapp:+15550000000'
                && $req['Body'] === 'hello';
        });
    }

    public function test_send_template_uses_content_sid_and_positional_vars(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->driver()->sendTemplate('+15551234567', 'HX_ORDER', ['Ali', '#42']);

        Http::assertSent(function ($req) {
            return $req['ContentSid'] === 'HX_ORDER'
                && $req['ContentVariables'] === json_encode(['1' => 'Ali', '2' => '#42']);
        });
    }

    public function test_rate_limit_is_retryable_other_4xx_terminal(): void
    {
        Http::fakeSequence('api.twilio.com/*')
            ->push(['code' => 20429], 429)
            ->push(['code' => 21211], 400);

        try {
            $this->driver()->sendText('+1', 'x');
            $this->fail('expected exception');
        } catch (WhatsAppProviderException $e) {
            $this->assertTrue($e->retryable); // 429 rate-limit
        }

        try {
            $this->driver()->sendText('+1', 'x');
            $this->fail('expected exception');
        } catch (WhatsAppProviderException $e) {
            $this->assertFalse($e->retryable); // 400 bad request -> terminal
            $this->assertSame('21211', $e->errorCode);
        }
    }

    public function test_parse_inbound_and_status(): void
    {
        $in = $this->driver()->parseInbound(['From' => 'whatsapp:+15551234567', 'Body' => 'hi', 'MessageSid' => 'SM1']);
        $this->assertSame('+15551234567', $in['from']);
        $this->assertSame('hi', $in['body']);

        $st = $this->driver()->parseStatus(['MessageSid' => 'SM1', 'MessageStatus' => 'undelivered']);
        $this->assertSame('failed', $st['status']);
    }

    public function test_service_routes_through_twilio_when_selected(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        WhatsAppProviderFactory::register('twilio', TwilioProvider::class);
        Credential::create([
            'store_id' => null, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'AC123', 'auth_token' => 'tok', 'from_number' => '+15550000000'],
            'active' => true,
        ]);
        config(['whatsapp.default_provider' => 'twilio', 'whatsapp.billing_mode' => 'quota_only']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMabc'], 201)]);

        // Business-initiated confirmation => approved template (Meta 24h-window rule).
        $res = app(WhatsAppService::class)->send('+15551234567', 'Order received', feature: 'order', template: 'HX_ORDER');

        $this->assertTrue($res->sent);
        $this->assertSame('twilio', $res->provider);
        $this->assertDatabaseHas('whatsapp_logs', ['provider' => 'twilio', 'status' => 'sent', 'feature' => 'order']);
        $this->assertDatabaseHas('whatsapp_usage', ['provider' => 'twilio', 'category' => 'utility', 'billable' => true]);
    }
}
