<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Modules\Messaging\Models\WhatsAppConsent;
use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Models\WhatsAppLog;
use Juwri\Modules\Messaging\Models\WhatsAppWindow;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 5); // P1-04 5b: fixture store ids must be real stores
    }

    public function test_forget_contact_erases_pii_but_keeps_optout(): void
    {
        $phone = '+15551234567';
        WhatsAppInbound::create(['from_phone' => $phone, 'body' => 'secret', 'received_at' => now()]);
        WhatsAppWindow::touchInbound(null, $phone, 'meta');
        WhatsAppConsent::optIn($phone, 5, 'web');
        WhatsAppLog::create(['to' => $phone, 'body_preview' => 'hi', 'provider' => 'meta', 'status' => 'sent', 'feature' => 'order']);

        $affected = app(WhatsAppService::class)->forgetContact($phone);

        $this->assertGreaterThanOrEqual(3, $affected);
        $this->assertDatabaseMissing('whatsapp_inbound_messages', ['from_phone' => $phone]);
        $this->assertDatabaseMissing('whatsapp_windows', ['phone' => $phone]);
        $this->assertDatabaseHas('whatsapp_logs', ['to' => 'REDACTED', 'body_preview' => null]);
    }

    public function test_purge_command_redacts_old_bodies_only(): void
    {
        $old = WhatsAppInbound::create(['from_phone' => '+1', 'body' => 'old', 'received_at' => now()->subDays(200)]);
        $old->created_at = now()->subDays(200);
        $old->save();

        $new = WhatsAppInbound::create(['from_phone' => '+2', 'body' => 'fresh', 'received_at' => now()]);

        config(['whatsapp.retention_days' => 90]);
        $this->artisan('whatsapp:purge')->assertExitCode(0);

        $this->assertNull($old->fresh()->body);
        $this->assertSame('fresh', $new->fresh()->body);
    }
}
