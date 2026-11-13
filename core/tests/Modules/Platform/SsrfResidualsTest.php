<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\Http;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\Drivers\OpenAiCompatibleProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-68: SSRF residuals — host-only guard (SMTP), the DNS test seam, and the
 * provider-returned AI image URL fetch.
 */
class SsrfResidualsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SsrfGuard::$resolver = static fn (string $h): array => match ($h) {
            'internal.example' => ['10.0.0.5'],
            'mixed.example' => ['93.184.216.34', '192.168.1.1'],
            'v6local.example' => ['fc00::1'],
            'unresolvable.example' => [],
            default => ['93.184.216.34'],
        };
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeHosts(): array
    {
        return [
            'loopback' => ['127.0.0.1'], 'localhost' => ['localhost'], 'metadata' => ['169.254.169.254'],
            'rfc1918' => ['10.1.2.3'], 'ipv6 loopback' => ['::1'], 'ipv6 ula' => ['fc00::1'], 'ipv6 link-local' => ['fe80::1'],
            'resolves private' => ['internal.example'], 'any private record' => ['mixed.example'],
            'resolves ula' => ['v6local.example'], 'unresolvable' => ['unresolvable.example'], 'junk' => ['a b@c'],
        ];
    }

    #[DataProvider('unsafeHosts')]
    public function test_unsafe_hosts_are_refused(string $host): void
    {
        $this->assertFalse(SsrfGuard::isPublicHost($host), $host);
        if (str_contains($host, ' ')) {
            return; // not a URL host at all
        }
        $this->assertNull(SsrfGuard::pinnedOptions('https://'.(str_contains($host, ':') ? "[$host]" : $host).'/x'), $host);
    }

    public function test_public_host_passes(): void
    {
        $this->assertTrue(SsrfGuard::isPublicHost('smtp.mailgun.example'));
        $this->assertTrue(SsrfGuard::isPublicHost('8.8.8.8'));
        $this->assertSame(['smtp.mailgun.example:443:93.184.216.34'], SsrfGuard::pinnedOptions('https://smtp.mailgun.example/')['curl'][CURLOPT_RESOLVE]);
    }

    public function test_redirect_to_private_is_refused(): void
    {
        $hook = SsrfGuard::redirectGuard()['on_redirect'];
        foreach (['https://internal.example/', 'https://169.254.169.254/latest/meta-data/', 'https://[::1]/'] as $to) {
            try {
                $hook(null, null, $to);
                $this->fail("redirect to $to was allowed");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('SSRF', $e->getMessage());
            }
        }
        $hook(null, null, 'https://cdn.example/ok.png'); // public hop passes
    }

    private function provider(): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider('openai', [
            'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-test',
            'supports_image_generation' => true, 'image_model' => 'gpt-image-1',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeImageUrls(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/a.png'], 'metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'resolves private' => ['https://internal.example/a.png'], 'ipv6 loopback' => ['https://[::1]/a.png'],
            'ipv6 ula' => ['https://[fc00::1]/a.png'], 'plain http' => ['http://cdn.example/a.png'],
        ];
    }

    #[DataProvider('unsafeImageUrls')]
    public function test_provider_image_url_to_private_is_never_fetched(string $url): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['url' => $url]]], 200),
            '*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);

        try {
            $this->provider()->generateImage('burger');
            $this->fail('unsafe image URL accepted');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('not a public https address', $e->getMessage());
        }
        Http::assertSentCount(1); // only the generations call
    }

    public function test_public_image_url_is_fetched_pinned(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['url' => 'https://cdn.example/a.png']]], 200),
            'cdn.example/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);

        $res = $this->provider()->generateImage('burger');
        $this->assertSame('PNGDATA', $res->bytes);
        $this->assertSame('image/png', $res->mime);
    }

    public function test_non_image_or_oversized_body_is_refused(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['url' => 'https://cdn.example/a.png']]], 200),
            'cdn.example/*' => Http::sequence()
                ->push('<html>', 200, ['Content-Type' => 'text/html'])
                ->push(str_repeat('x', OpenAiCompatibleProvider::IMAGE_FETCH_MAX_BYTES + 1), 200, ['Content-Type' => 'image/png']),
        ]);

        foreach (['did not return an image', 'size cap'] as $expect) {
            try {
                $this->provider()->generateImage('burger');
                $this->fail('accepted');
            } catch (AiProviderException $e) {
                $this->assertStringContainsString($expect, $e->getMessage());
            }
        }
    }
}
