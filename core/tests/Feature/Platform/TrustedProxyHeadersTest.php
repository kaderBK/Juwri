<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P6-51: X-Forwarded-* are believed only from TRUSTED_PROXIES (config/trustedproxy.php, docker/entrypoint.sh default).
 */
class TrustedProxyHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_p651/ip', static fn (Request $r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure()]));
        // prefixed: a bare numeric throttle keys on domain|ip only, so it would share one counter
        // with every other numeric route AND trip RouteThrottlePrefixTest once these routes leak
        // into the shared route collection (this test never unregisters them).
        Route::middleware('throttle:2,1,p651-limited')->get('/_p651/limited', static fn (Request $r) => response()->json(['ip' => $r->ip()]));
        Route::get('/_p651/cookie', static fn () => response('ok')->cookie(cookie('p651', 'v')));
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    /** @param array<string, string> $headers */
    private function via(string $peer, array $headers = []): self
    {
        $server = ['REMOTE_ADDR' => $peer];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->withServerVariables($server);
    }

    public function test_forwarded_for_from_a_trusted_peer_is_the_client_ip(): void
    {
        config(['trustedproxy.proxies' => ['172.16.0.0/12']]);

        $this->via('172.18.0.5', ['X-Forwarded-For' => '198.51.100.7'])->get('http://localhost/_p651/ip')
            ->assertOk()->assertJsonPath('ip', '198.51.100.7');
    }

    public function test_forwarded_for_from_an_untrusted_peer_is_ignored(): void
    {
        config(['trustedproxy.proxies' => ['172.16.0.0/12']]);

        $this->via('203.0.113.9', ['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])->get('http://localhost/_p651/ip')
            ->assertOk()->assertJsonPath('ip', '203.0.113.9')->assertJsonPath('secure', false);
    }

    public function test_empty_list_trusts_nothing(): void
    {
        config(['trustedproxy.proxies' => []]);

        $this->via('10.0.0.2', ['X-Forwarded-For' => '198.51.100.7'])->get('http://localhost/_p651/ip')
            ->assertJsonPath('ip', '10.0.0.2');
    }

    public function test_two_clients_behind_the_proxy_get_separate_throttle_buckets(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        foreach ([1, 2] as $_) {
            $this->via('10.0.0.2', ['X-Forwarded-For' => '198.51.100.1'])->get('/_p651/limited')->assertOk();
        }
        $this->via('10.0.0.2', ['X-Forwarded-For' => '198.51.100.1'])->get('/_p651/limited')->assertStatus(429);
        // a second visitor through the same proxy still has its own budget
        $this->via('10.0.0.2', ['X-Forwarded-For' => '198.51.100.2'])->get('/_p651/limited')
            ->assertOk()->assertJsonPath('ip', '198.51.100.2');
    }

    public function test_forwarded_proto_https_from_trusted_peer_marks_request_and_cookie_secure(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8'], 'session.secure' => null]);

        // tests run with APP_URL=https://localhost: use plain http so only the forwarded header can make it secure
        $this->via('10.0.0.2')->get('http://localhost/_p651/ip')->assertJsonPath('secure', false);
        $this->via('10.0.0.2', ['X-Forwarded-Proto' => 'https'])->get('http://localhost/_p651/ip')->assertJsonPath('secure', true);

        $cookie = collect($this->via('10.0.0.2', ['X-Forwarded-Proto' => 'https'])->get('http://localhost/_p651/cookie')
            ->headers->getCookies())->first(fn ($c) => $c->getName() === 'p651');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_config_never_defaults_to_wildcard(): void
    {
        $proxies = require base_path('config/trustedproxy.php');
        $this->assertNotSame('*', $proxies['proxies']);
    }
}
