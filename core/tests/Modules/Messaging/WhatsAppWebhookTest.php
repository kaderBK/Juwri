<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Juwri\Modules\Messaging\Http\Controllers\WhatsAppWebhookController;
use Juwri\Modules\Messaging\Jobs\ProcessWhatsAppWebhookJob;
use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Models\WhatsAppLog;
use Juwri\Modules\Messaging\Models\WhatsAppOptout;
use Juwri\Modules\Messaging\Models\WhatsAppRoute;
use Juwri\Modules\Messaging\Models\WhatsAppWindow;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\TwilioProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppWebhookProcessor;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(7); // P1-04 5b: fixture store ids must be real stores
        WhatsAppProviderFactory::register('twilio', TwilioProvider::class);
    }

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        parent::tearDown();
    }

    private function process(array $payload): void
    {
        app(WhatsAppWebhookProcessor::class)->handle('twilio', $payload);
    }

    public function test_inbound_creates_message_routes_tenant_and_opens_window(): void
    {
        WhatsAppRoute::create(['provider' => 'twilio', 'sender_id' => '+15550000000', 'store_id' => 7]);

        $this->process([
            'From' => 'whatsapp:+15551234567', 'To' => 'whatsapp:+15550000000',
            'Body' => 'Table for 2?', 'MessageSid' => 'SMin1',
        ]);

        $this->assertDatabaseHas('whatsapp_inbound_messages', [
            'store_id' => 7, 'from_phone' => '+15551234567', 'body' => 'Table for 2?', 'message_id' => 'SMin1',
        ]);
        // Inbound opened the 24h window.
        $this->assertTrue(WhatsAppWindow::isOpen(7, '+15551234567'));
    }

    public function test_duplicate_inbound_is_ignored(): void
    {
        $payload = ['From' => 'whatsapp:+1', 'To' => 'whatsapp:+2', 'Body' => 'hi', 'MessageSid' => 'DUP1'];
        $this->process($payload);
        $this->process($payload);

        $this->assertSame(1, WhatsAppInbound::where('message_id', 'DUP1')->count());
    }

    public function test_retry_after_a_failed_inbound_insert_is_not_swallowed_as_duplicate(): void
    {
        // P6-64: claim + inbound row commit together — a queue retry after a failed insert
        // stores the message once instead of being dropped as a re-delivery.
        $payload = ['From' => 'whatsapp:+1', 'To' => 'whatsapp:+2', 'Body' => 'hi', 'MessageSid' => 'RETRY1'];
        $fail = true;
        WhatsAppInbound::creating(function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('simulated insert failure');
            }
        });

        try {
            $this->process($payload);
            $this->fail('first attempt should throw');
        } catch (\RuntimeException) {
        }
        $this->process($payload);
        $this->process($payload);

        $this->assertSame(1, WhatsAppInbound::where('message_id', 'RETRY1')->count());
    }

    public function test_stop_keyword_opts_out(): void
    {
        $this->process(['From' => 'whatsapp:+15559999999', 'To' => 'whatsapp:+1', 'Body' => 'STOP', 'MessageSid' => 'SMstop']);
        $this->assertTrue(WhatsAppOptout::isOptedOut('+15559999999'));
    }

    public function test_status_callback_updates_outbound_log(): void
    {
        WhatsAppLog::create(['to' => '+1', 'provider' => 'twilio', 'status' => 'sent', 'provider_msg_id' => 'SMout9', 'feature' => 'order']);

        $this->process(['MessageSid' => 'SMout9', 'MessageStatus' => 'delivered']);

        $log = WhatsAppLog::where('provider_msg_id', 'SMout9')->first();
        $this->assertSame('delivered', $log->status);
        $this->assertNotNull($log->delivered_at);
    }

    /** P6-100: status callbacks arrive out of order — a late "sent"/"delivered" never moves a read message back. */
    public function test_out_of_order_status_callbacks_never_regress(): void
    {
        WhatsAppLog::create(['to' => '+1', 'provider' => 'twilio', 'status' => 'sent', 'provider_msg_id' => 'SMord1', 'feature' => 'order']);

        $this->process(['MessageSid' => 'SMord1', 'MessageStatus' => 'read']);
        $this->process(['MessageSid' => 'SMord1', 'MessageStatus' => 'delivered']);
        $this->process(['MessageSid' => 'SMord1', 'MessageStatus' => 'sent']);

        $this->assertSame('read', WhatsAppLog::where('provider_msg_id', 'SMord1')->value('status'));
    }

    /** P6-100: a duplicated inbound message is stored once and auto-replied once. */
    public function test_duplicate_inbound_replies_once(): void
    {
        $chatbot = new class extends WhatsAppChatbot
        {
            public int $replies = 0;

            public function __construct() {}

            public function maybeReply(?int $storeId, string $from, string $body, string $provider): void
            {
                $this->replies++;
            }
        };
        app()->instance(WhatsAppChatbot::class, $chatbot);
        $payload = ['From' => 'whatsapp:+1', 'To' => 'whatsapp:+2', 'Body' => 'order 2 pizzas', 'MessageSid' => 'DUPREPLY'];

        $this->process($payload);
        $this->process($payload);

        $this->assertSame(1, WhatsAppInbound::where('message_id', 'DUPREPLY')->count());
        $this->assertSame(1, $chatbot->replies);
    }

    public function test_twilio_signature_authentication(): void
    {
        $driver = new TwilioProvider('twilio', ['sid' => 'AC1', 'auth_token' => 'secrettoken', 'from_number' => '+1']);
        $url = 'https://app.test/webhook/whatsapp/twilio';
        $params = ['Body' => 'hi', 'From' => 'whatsapp:+1', 'MessageSid' => 'SM1'];

        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        $valid = base64_encode(hash_hmac('sha1', $data, 'secrettoken', true));

        $this->assertTrue($driver->authenticate(['x-twilio-signature' => [$valid]], '', $url, $params));
        $this->assertFalse($driver->authenticate(['x-twilio-signature' => ['wrong']], '', $url, $params));
    }

    public function test_controller_dispatches_job_and_returns_200(): void
    {
        Bus::fake();
        config(['settings.whatsapp_verify_signatures' => '0']);

        $request = Request::create('/webhook/whatsapp/twilio', 'POST', [
            'From' => 'whatsapp:+1', 'Body' => 'hi', 'MessageSid' => 'SMc1',
        ]);

        $response = app(WhatsAppWebhookController::class)->handle($request, 'twilio');

        $this->assertSame(200, $response->getStatusCode());
        Bus::assertDispatched(ProcessWhatsAppWebhookJob::class);
    }
}
