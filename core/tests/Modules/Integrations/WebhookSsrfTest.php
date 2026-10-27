<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use App\Models\WebhookEndpoint;
use Juwri\Core\Services\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-4 (platform, security-critical): SSRF guard on webhook URLs + auto-disable
 * of a persistently failing endpoint.
 */
class WebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_blocks_unsafe_urls(): void
    {
        $this->assertFalse(SsrfGuard::isSafePublicUrl('http://example.com'), 'non-https blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://127.0.0.1'), 'loopback blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://10.0.0.1'), 'private-A blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://192.168.1.1'), 'private-C blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://169.254.169.254'), 'metadata blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://[::1]'), 'ipv6 loopback blocked');
        $this->assertFalse(SsrfGuard::isSafePublicUrl('garbage'), 'malformed blocked');
    }

    public function test_guard_allows_public_https(): void
    {
        // Literal public IPs — no DNS, so this stays deterministic offline.
        $this->assertTrue(SsrfGuard::isSafePublicUrl('https://1.1.1.1'));
        $this->assertTrue(SsrfGuard::isSafePublicUrl('https://8.8.8.8/webhook'));
    }

    public function test_blocked_url_delivery_records_failure(): void
    {
        $ep = WebhookEndpoint::create([
            'user_id' => 1, 'url' => 'https://127.0.0.1/hook',
            'events' => ['*'], 'active' => true, 'secret' => 's',
        ]);

        DeliverWebhookJob::dispatchSync($ep->id, 'order.created', ['x' => 1], 'msg_' . Str::random(8), now()->getTimestamp());

        $ep->refresh();
        $this->assertSame(1, $ep->consecutive_failures);
        $this->assertDatabaseHas('webhook_deliveries', ['endpoint_id' => $ep->id, 'success' => false]);
    }

    public function test_endpoint_auto_disables_after_max_failures(): void
    {
        $ep = WebhookEndpoint::create([
            'user_id' => 1, 'url' => 'https://10.0.0.1/hook',
            'events' => ['*'], 'active' => true, 'secret' => 's',
        ]);

        for ($i = 0; $i < WebhookEndpoint::MAX_CONSECUTIVE_FAILURES; $i++) {
            DeliverWebhookJob::dispatchSync($ep->id, 'order.created', ['x' => 1], 'msg_' . Str::random(8), now()->getTimestamp());
        }

        $ep->refresh();
        $this->assertFalse($ep->active, 'endpoint should be auto-disabled');
        $this->assertNotNull($ep->disabled_reason);
        $this->assertGreaterThanOrEqual(WebhookEndpoint::MAX_CONSECUTIVE_FAILURES, $ep->consecutive_failures);
    }
}
