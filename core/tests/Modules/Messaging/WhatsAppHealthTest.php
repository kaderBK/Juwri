<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Models\WhatsAppConsent;
use Juwri\Modules\Messaging\Models\WhatsAppNumberHealth;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\MetaProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\TwilioProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppBroadcast;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppWebhookProcessor;
use Tests\TestCase;

class WhatsAppHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        parent::tearDown();
    }

    private function storeFixture(): Store
    {
        return Store::create([
            'user_id' => User::factory()->create(['status' => 1])->id,
            'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_health_record_and_is_healthy(): void
    {
        $this->assertTrue(WhatsAppNumberHealth::isHealthy('meta', '+1S')); // unknown -> healthy

        WhatsAppNumberHealth::record('meta', '+1S', ['status' => 'flagged', 'quality' => 'red']);
        $this->assertFalse(WhatsAppNumberHealth::isHealthy('meta', '+1S'));

        WhatsAppNumberHealth::record('meta', '+1S', ['status' => 'connected', 'quality' => 'green']);
        $this->assertTrue(WhatsAppNumberHealth::isHealthy('meta', '+1S'));
    }

    public function test_meta_parses_flagged_quality_event(): void
    {
        $h = new MetaProvider('meta', [])->parseHealth([
            'entry' => [['changes' => [[
                'field' => 'phone_number_quality_update',
                'value' => ['display_phone_number' => '+15550000000', 'event' => 'FLAGGED', 'current_limit' => 'TIER_1K'],
            ]]]],
        ]);

        $this->assertSame('+15550000000', $h['sender_id']);
        $this->assertSame('flagged', $h['status']);
        $this->assertSame('red', $h['quality']);
        $this->assertSame('TIER_1K', $h['messaging_tier']);
    }

    public function test_processor_records_health(): void
    {
        WhatsAppProviderFactory::register('meta', MetaProvider::class);
        app(WhatsAppWebhookProcessor::class)->handle('meta', [
            'entry' => [['changes' => [[
                'field' => 'account_update',
                'value' => ['display_phone_number' => '+15551112222', 'event' => 'FLAGGED'],
            ]]]],
        ]);

        $this->assertDatabaseHas('whatsapp_number_healths', ['sender_id' => '+15551112222', 'status' => 'flagged']);
    }

    public function test_broadcast_pauses_when_sender_flagged(): void
    {
        Bus::fake();
        WhatsAppProviderFactory::register('twilio', TwilioProvider::class);
        config(['whatsapp.default_provider' => 'twilio', 'settings.whatsapp_default_provider' => 'twilio']);

        $store = $this->storeFixture();
        Credential::create(['store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'AC1', 'auth_token' => 't', 'from_number' => '+15559999999'], 'active' => true]);
        WhatsAppConsent::optIn('+1AA', $store->id, 'web');
        WhatsAppNumberHealth::record('twilio', '+15559999999', ['status' => 'flagged', 'quality' => 'red']);

        $count = app(WhatsAppBroadcast::class)->send($store->id, 'Promo', 'promo');

        $this->assertSame(-1, $count);
        Bus::assertNotDispatched(SendWhatsAppJob::class);
    }

    public function test_broadcast_runs_when_sender_healthy(): void
    {
        Bus::fake();
        WhatsAppProviderFactory::register('twilio', TwilioProvider::class);
        config(['whatsapp.default_provider' => 'twilio', 'settings.whatsapp_default_provider' => 'twilio']);

        $store = $this->storeFixture();
        Credential::create(['store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'AC1', 'auth_token' => 't', 'from_number' => '+15558888888'], 'active' => true]);
        WhatsAppConsent::optIn('+1AA', $store->id, 'web');
        // No health row -> healthy.

        $count = app(WhatsAppBroadcast::class)->send($store->id, 'Promo', 'promo');

        $this->assertSame(1, $count);
        Bus::assertDispatched(SendWhatsAppJob::class, 1);
    }
}
