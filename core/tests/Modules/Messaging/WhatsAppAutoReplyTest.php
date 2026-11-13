<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Models\WhatsAppRoute;
use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppWebhookProcessor;
use Mockery;
use Tests\TestCase;

/** Fake driver that always "sends" (text + template). */
class AutoReplyFakeProvider extends AbstractWhatsAppProvider
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return array_merge(parent::capabilities(), ['send' => true, 'template' => true]);
    }

    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        return WhatsAppResult::sent('afake', 'm1');
    }

    public function sendTemplate(string $to, string $t, array $v = [], array $o = []): WhatsAppResult
    {
        return WhatsAppResult::sent('afake', 'm2');
    }

    public function parseInbound(array $p): ?array
    {
        if (empty($p['From'])) {
            return null;
        }

        return [
            'from' => str_replace('whatsapp:', '', $p['From']),
            'body' => $p['Body'] ?? '',
            'message_id' => $p['MessageSid'] ?? null,
            'media' => [],
            'to' => isset($p['To']) ? str_replace('whatsapp:', '', $p['To']) : null,
        ];
    }
}

class WhatsAppAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WhatsAppProviderFactory::register('afake', AutoReplyFakeProvider::class);
        config(['whatsapp.default_provider' => 'afake', 'whatsapp.billing_mode' => 'quota_only']);
    }

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        parent::tearDown();
    }

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111',
            'title' => 'Pizza Place', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --- Auto-send job ----------------------------------------------------

    public function test_send_whatsapp_job_delivers_template(): void
    {
        new SendWhatsAppJob('+15551234567', 'Order #7 received', 1, 'order', false, 'order_confirm', ['Pizza', '7'])
            ->handle(app(WhatsAppService::class));

        $this->assertDatabaseHas('whatsapp_logs', ['provider' => 'afake', 'status' => 'sent', 'feature' => 'order']);
    }

    public function test_send_whatsapp_job_noops_on_empty_recipient(): void
    {
        new SendWhatsAppJob('   ', 'x', 1, 'order')->handle(app(WhatsAppService::class));
        $this->assertDatabaseCount('whatsapp_logs', 0);
    }

    // --- Chatbot ----------------------------------------------------------

    private function mockAi(string $reply): void
    {
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('complete')->andReturn($reply);
        $this->instance(AiService::class, $ai);
    }

    private function inbound(int $storeId, string $body): void
    {
        WhatsAppRoute::create(['provider' => 'afake', 'sender_id' => 'PN1', 'store_id' => $storeId]);
        app(WhatsAppWebhookProcessor::class)->handle('afake', [
            'From' => 'whatsapp:+15551234567', 'To' => 'whatsapp:PN1', 'Body' => $body, 'MessageSid' => 'IN'.Str::random(6),
        ]);
    }

    public function test_chatbot_replies_when_enabled(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'whatsapp_chatbot_enable', '1');
        $this->mockAi('We open at 9am!');

        $this->inbound($r->id, 'what time do you open?');

        // The AI reply was sent back to the customer (outbound, in-window free service).
        $this->assertDatabaseHas('whatsapp_logs', [
            'provider' => 'afake', 'feature' => 'general', 'direction' => 'out', 'body_preview' => 'We open at 9am!',
        ]);
    }

    public function test_chatbot_silent_when_disabled(): void
    {
        $r = $this->storeFixture(); // no chatbot flag
        $this->inbound($r->id, 'hello');
        $this->assertDatabaseCount('whatsapp_logs', 0); // inbound only, no reply
    }

    public function test_chatbot_ignores_control_keywords(): void
    {
        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'whatsapp_chatbot_enable', '1');
        $this->inbound($r->id, 'STOP');
        $this->assertDatabaseCount('whatsapp_logs', 0); // opt-out handled, not chatted
    }
}
