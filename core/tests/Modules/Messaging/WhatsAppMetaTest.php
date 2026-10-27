<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\MetaProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderException;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppMetaTest extends TestCase
{
    use RefreshDatabase;

    private function driver(array $extra = []): MetaProvider
    {
        return new MetaProvider('meta', array_merge([
            'access_token' => 'EAAtoken', 'phone_number_id' => 'PNID1',
        ], $extra));
    }

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        parent::tearDown();
    }

    public function test_is_configured(): void
    {
        $this->assertTrue($this->driver()->isConfigured());
        $this->assertFalse(new MetaProvider('meta', ['access_token' => 'x'])->isConfigured());
    }

    public function test_send_text_posts_graph_api_json(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

        $res = $this->driver()->sendText('+1 555 123 4567', 'hi there');

        $this->assertTrue($res->sent);
        $this->assertSame('wamid.OUT1', $res->providerMsgId);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/PNID1/messages')
                && $req['messaging_product'] === 'whatsapp'
                && $req['to'] === '15551234567'
                && $req['type'] === 'text'
                && $req['text']['body'] === 'hi there';
        });
    }

    public function test_send_template_builds_components(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]], 200)]);

        $this->driver()->sendTemplate('+15551234567', 'order_confirm', ['Ali', '#42'], ['language' => 'en']);

        Http::assertSent(function ($req) {
            return $req['type'] === 'template'
                && $req['template']['name'] === 'order_confirm'
                && $req['template']['components'][0]['parameters'][1]['text'] === '#42';
        });
    }

    public function test_error_code_mapping(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131048, 'message' => 'spam']], 400)]);
        try {
            $this->driver()->sendText('+1', 'x');
            $this->fail('expected');
        } catch (WhatsAppProviderException $e) {
            $this->assertTrue($e->retryable); // 131048 is retryable
            $this->assertSame('131048', $e->errorCode);
        }
    }

    public function test_verify_webhook_challenge(): void
    {
        $d = $this->driver(['webhook_verify_token' => 'vtok']);
        $this->assertSame('CHAL', $d->verifyWebhook(['hub_mode' => 'subscribe', 'hub_verify_token' => 'vtok', 'hub_challenge' => 'CHAL'], [], ''));
        $this->assertFalse($d->verifyWebhook(['hub_mode' => 'subscribe', 'hub_verify_token' => 'wrong', 'hub_challenge' => 'CHAL'], [], ''));
    }

    public function test_authenticate_hub_signature(): void
    {
        $d = $this->driver(['app_secret' => 'appsecret']);
        $raw = '{"entry":[]}';
        $sig = 'sha256=' . hash_hmac('sha256', $raw, 'appsecret');
        $this->assertTrue($d->authenticate(['x-hub-signature-256' => [$sig]], $raw, '', []));
        $this->assertFalse($d->authenticate(['x-hub-signature-256' => ['sha256=deadbeef']], $raw, '', []));
    }

    public function test_parse_inbound_and_status_envelope(): void
    {
        $in = $this->driver()->parseInbound(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'PNID1'],
            'contacts' => [['profile' => ['name' => 'Sam']]],
            'messages' => [['from' => '15551234567', 'id' => 'wamid.IN', 'text' => ['body' => 'hello']]],
        ]]]]]]);

        $this->assertSame('+15551234567', $in['from']);
        $this->assertSame('hello', $in['body']);
        $this->assertSame('Sam', $in['contact_name']);
        $this->assertSame('PNID1', $in['to']);

        $st = $this->driver()->parseStatus(['entry' => [['changes' => [['value' => [
            'statuses' => [['id' => 'wamid.OUT', 'status' => 'read']],
        ]]]]]]);
        $this->assertSame('read', $st['status']);
        $this->assertSame('wamid.OUT', $st['provider_msg_id']);
    }

    public function test_processor_handles_meta_inbound(): void
    {
        WhatsAppProviderFactory::register('meta', MetaProvider::class);

        app(WhatsAppWebhookProcessor::class)->handle('meta', ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'PNID1'],
            'messages' => [['from' => '15551234567', 'id' => 'wamid.INX', 'text' => ['body' => 'menu?']]],
        ]]]]]]);

        $this->assertDatabaseHas('whatsapp_inbound_messages', ['from_phone' => '+15551234567', 'body' => 'menu?', 'message_id' => 'wamid.INX']);
    }
}
