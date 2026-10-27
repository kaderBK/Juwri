<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Messaging\Models\WhatsAppWindow;
use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\LinkProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderException;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Tests\TestCase;

/** A registered driver that always blows up, to exercise link fallback. */
class ExplodingProvider extends AbstractWhatsAppProvider
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return array_merge(parent::capabilities(), ['send' => true]);
    }

    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        throw new WhatsAppProviderException('boom', 'exploding', retryable: true, errorCode: '500');
    }
}

class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        parent::tearDown();
    }

    // --- LinkProvider URL building ---------------------------------------

    public function test_link_provider_builds_wa_me_url_with_encoded_message(): void
    {
        $url = LinkProvider::buildUrl('+1 (234) 567-8900', 'Order #5 total $9.00');

        $this->assertSame(
            'https://api.whatsapp.com/send?phone=12345678900&text='.urlencode('Order #5 total $9.00'),
            $url
        );
    }

    public function test_link_provider_strips_non_digits_and_omits_empty_message(): void
    {
        $this->assertSame('https://api.whatsapp.com/send?phone=447700900123', LinkProvider::buildUrl('+44 7700 900123'));
    }

    public function test_whatsapp_link_helper_matches_driver(): void
    {
        $this->assertSame(LinkProvider::buildUrl('123', 'hi'), whatsapp_link('123', 'hi'));
    }

    // --- Factory ----------------------------------------------------------

    public function test_factory_defaults_to_link_provider(): void
    {
        $provider = app(WhatsAppProviderFactory::class)->make(null);
        $this->assertSame('link', $provider->name());
        $this->assertTrue($provider->isConfigured());
    }

    public function test_available_lists_registered_providers_including_link(): void
    {
        WhatsAppProviderFactory::register('exploding', ExplodingProvider::class);
        $this->assertEqualsCanonicalizing(['link', 'exploding'], WhatsAppProviderFactory::available());
    }

    // --- Service send + logging ------------------------------------------

    public function test_send_via_link_returns_link_result_and_logs(): void
    {
        $result = app(WhatsAppService::class)->send('+1234567890', 'hello', storeId: null, feature: 'order');

        $this->assertSame('link', $result->status);
        $this->assertFalse($result->sent);
        $this->assertStringContainsString('phone=1234567890', $result->link);

        $this->assertDatabaseHas('whatsapp_logs', [
            'provider' => 'link', 'status' => 'link', 'feature' => 'order', 'direction' => 'out',
        ]);
    }

    public function test_send_empty_recipient_fails_and_logs(): void
    {
        $result = app(WhatsAppService::class)->send('   ', 'hi');
        $this->assertSame('failed', $result->status);
        $this->assertDatabaseHas('whatsapp_logs', ['status' => 'failed']);
    }

    public function test_provider_failure_falls_back_to_link(): void
    {
        config(['whatsapp.default_provider' => 'exploding', 'whatsapp.fallback_to_link' => true]);
        WhatsAppProviderFactory::register('exploding', ExplodingProvider::class);
        // Open a session window so the free-form send reaches the provider (and blows up).
        WhatsAppWindow::touchInbound(null, '+1234567890', 'exploding');

        $result = app(WhatsAppService::class)->send('+1234567890', 'hi', feature: 'order');

        // Exploding provider threw -> service degraded to a wa.me link.
        $this->assertSame('link', $result->status);
        $this->assertStringContainsString('phone=1234567890', $result->link);
    }
}
