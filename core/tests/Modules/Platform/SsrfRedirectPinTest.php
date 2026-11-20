<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Services\SsrfGuard;
use Tests\TestCase;

/**
 * Security w5 #5: a redirect hop must be IP-pinned like the first request.
 * Before the fix Guzzle followed a 30x to ANOTHER host with only the first
 * host's CURLOPT_RESOLVE entry, so curl re-resolved the hop host itself and a
 * DNS-rebinding hop host could answer the vet with a public IP and the real
 * connect with an internal one.
 */
class SsrfRedirectPinTest extends TestCase
{
    /** @var array<string, mixed> url => CURLOPT_RESOLVE list seen by that request */
    private array $pins = [];

    protected function setUp(): void
    {
        parent::setUp();
        SsrfGuard::$resolver = static fn (string $h): array => match ($h) {
            'internal.example' => ['10.0.0.5'],
            'cdn.example' => ['93.184.216.35'],
            default => ['93.184.216.34'],
        };
    }

    protected function tearDown(): void
    {
        SsrfGuard::$resolver = null;
        parent::tearDown();
    }

    /** @param  array<string, array{0: int, 1: string|array<mixed>, 2?: array<string, string>}>  $routes  url => [status, body, headers] */
    private function fakeRoutes(array $routes): void
    {
        Http::fake(function (Request $request, array $options) use ($routes) {
            $url = $request->url();
            $this->pins[$url] = $options['curl'][CURLOPT_RESOLVE] ?? null;
            [$status, $body, $headers] = $routes[$url] + [2 => []];

            return Http::response($body, $status, $headers);
        });
    }

    public function test_pinned_options_never_follow_a_redirect_to_another_host_unpinned(): void
    {
        $this->fakeRoutes([
            'https://public.example/a' => [302, '', ['Location' => 'https://cdn.example/x']],
            'https://cdn.example/x' => [200, 'OK'],
        ]);

        try {
            Http::withOptions(SsrfGuard::pinnedOptions('https://public.example/a'))->get('https://public.example/a');
        } catch (\Throwable) {
            // refusing the cross-host hop is the fixed behaviour
        }

        $this->assertArrayNotHasKey('https://cdn.example/x', $this->pins, 'cross-host hop was sent without its own IP pin');
    }

    public function test_pinned_options_still_follow_a_same_host_redirect_pinned(): void
    {
        $this->fakeRoutes([
            'https://public.example/a' => [302, '', ['Location' => '/b']],
            'https://public.example/b' => [200, 'OK'],
        ]);

        $res = Http::withOptions(SsrfGuard::pinnedOptions('https://public.example/a'))->get('https://public.example/a');

        $this->assertSame('OK', $res->body());
        $this->assertSame(['public.example:443:93.184.216.34'], $this->pins['https://public.example/b']);
    }

    public function test_send_pins_every_cross_host_hop_to_its_own_vetted_ip(): void
    {
        $this->fakeRoutes([
            'https://public.example/a' => [302, '', ['Location' => 'https://cdn.example/x']],
            'https://cdn.example/x' => [200, 'IMG'],
        ]);

        $res = SsrfGuard::send(Http::timeout(5), 'GET', 'https://public.example/a');

        $this->assertSame('IMG', $res->body());
        $this->assertSame(['public.example:443:93.184.216.34'], $this->pins['https://public.example/a']);
        $this->assertSame(['cdn.example:443:93.184.216.35'], $this->pins['https://cdn.example/x']);
    }

    public function test_send_refuses_a_hop_to_an_internal_host_before_sending_it(): void
    {
        $this->fakeRoutes([
            'https://public.example/a' => [302, '', ['Location' => 'https://internal.example/admin']],
            'https://internal.example/admin' => [200, 'SECRET'],
        ]);

        try {
            SsrfGuard::send(Http::timeout(5), 'GET', 'https://public.example/a');
            $this->fail('redirect to an internal host was followed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SSRF', $e->getMessage());
        }
        $this->assertArrayNotHasKey('https://internal.example/admin', $this->pins);
    }

    public function test_send_bounds_the_number_of_hops(): void
    {
        $this->fakeRoutes(['https://public.example/loop' => [302, '', ['Location' => 'https://public.example/loop']]]);

        try {
            SsrfGuard::send(Http::timeout(5), 'GET', 'https://public.example/loop', [], 3);
            $this->fail('redirect loop was followed forever');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('too many redirects', $e->getMessage());
        }
        Http::assertSentCount(4); // first request + 3 hops
    }

    public function test_send_switches_to_get_on_303_and_refuses_credentials_cross_host(): void
    {
        $this->fakeRoutes([
            'https://public.example/post' => [303, '', ['Location' => '/done']],
            'https://public.example/done' => [200, 'DONE'],
            'https://public.example/auth' => [302, '', ['Location' => 'https://cdn.example/x']],
            'https://cdn.example/x' => [200, 'LEAK'],
        ]);

        $res = SsrfGuard::send(Http::timeout(5), 'POST', 'https://public.example/post', ['json' => ['a' => 1]]);
        $this->assertSame('DONE', $res->body());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://public.example/done' && $r->method() === 'GET');

        try {
            SsrfGuard::send(Http::withHeaders(['Authorization' => 'Client-ID k']), 'GET', 'https://public.example/auth');
            $this->fail('credentials were carried to another host');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SSRF', $e->getMessage());
        }
        $this->assertArrayNotHasKey('https://cdn.example/x', $this->pins);
    }
}
