<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** P6-219: CGNAT, NAT64, IPv4-mapped and other special ranges, checked after DNS. */
class SsrfSpecialRangesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SsrfGuard::$resolver = null;
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function blocked(): array
    {
        return array_combine($l = [
            '100.64.0.1', '100.100.100.200', '100.127.255.254', '198.18.0.1', '198.19.255.1',
            '192.0.0.8', '192.0.2.1', '198.51.100.1', '203.0.113.1', '192.88.99.1',
            '224.0.0.1', '255.255.255.255', '0.0.0.0', '127.0.0.1', '169.254.169.254', '172.16.0.1',
            '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:100.64.0.1', '::ffff:10.0.0.1',
            '::127.0.0.1', '::', '::1', '64:ff9b::a9fe:a9fe', '64:ff9b::808:808', '64:ff9b:1::1',
            '2002:7f00:1::1', '2001::1', '2001:db8::1', 'fe80::1', 'fec0::1', 'fd00::1', 'ff02::1', '100::1',
        ], array_map(fn ($ip) => [$ip], $l));
    }

    #[DataProvider('blocked')]
    public function test_blocked_after_dns_for_every_entry_point(string $ip): void
    {
        SsrfGuard::$resolver = fn () => [$ip];
        $this->assertFalse(SsrfGuard::isPublicIp($ip), "literal {$ip}");
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://evil.example/x'), "url via DNS {$ip}");
        $this->assertNull(SsrfGuard::pin('https://evil.example/x'));
        $this->assertNull(SsrfGuard::pinnedOptions('https://evil.example/x'), 'import-from-URL / Guzzle callers');
        $this->assertFalse(SsrfGuard::isPublicHost('evil.example'), 'SMTP / host callers');
    }

    public function test_one_bad_record_among_good_ones_blocks(): void
    {
        SsrfGuard::$resolver = fn () => ['8.8.8.8', '64:ff9b::a9fe:a9fe'];
        $this->assertFalse(SsrfGuard::isSafePublicUrl('https://mixed.example'));
    }

    public function test_public_addresses_still_allowed(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.1', '198.20.0.1', '2606:4700:4700::1111', '2a00:1450::1'] as $ip) {
            $this->assertTrue(SsrfGuard::isPublicIp($ip), $ip);
        }
    }

    public function test_webhook_to_cgnat_host_is_blocked(): void
    {
        SsrfGuard::$resolver = fn () => ['100.100.100.200'];
        $ep = WebhookEndpoint::create([
            'user_id' => 1, 'url' => 'https://meta.example/hook',
            'events' => ['*'], 'active' => true, 'secret' => 's',
        ]);
        DeliverWebhookJob::$transport = fn () => $this->fail('must not send');

        DeliverWebhookJob::dispatchSync($ep->id, 'order.created', ['x' => 1], 'msg_'.Str::random(8), now()->getTimestamp());
        DeliverWebhookJob::$transport = null;

        $this->assertDatabaseHas('webhook_deliveries', ['endpoint_id' => $ep->id, 'success' => false, 'http_status' => null]);
    }
}
