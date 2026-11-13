<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Services\SsrfGuard;
use Tests\TestCase;

/**
 * Security (F6): the SSRF guard resolves a host once and pins the request to
 * that vetted address (CURLOPT_RESOLVE), closing the DNS-rebinding window
 * between the check and the fetch.
 */
class SsrfGuardPinTest extends TestCase
{
    public function test_safe_url_is_pinned_to_the_vetted_ip_and_port(): void
    {
        $opts = SsrfGuard::pinnedOptions('https://8.8.8.8:8443/hook');

        $this->assertSame(['8.8.8.8:8443:8.8.8.8'], $opts['curl'][CURLOPT_RESOLVE]);
        $this->assertIsArray($opts['allow_redirects'], 'redirect hops stay guarded');
        $this->assertFalse(SsrfGuard::pinnedOptions('https://1.1.1.1/', false)['allow_redirects']);
    }

    public function test_ipv6_is_bracketed(): void
    {
        $this->assertSame('example.test:443:[2606:4700::1111]', SsrfGuard::resolveEntry(['host' => 'example.test', 'port' => 443, 'ip' => '2606:4700::1111']));
    }

    public function test_unsafe_urls_get_no_options_at_all(): void
    {
        foreach (['https://169.254.169.254/latest/meta-data/', 'https://127.0.0.1/', 'https://10.0.0.5/', 'http://8.8.8.8/', 'https://[::1]/'] as $url) {
            $this->assertNull(SsrfGuard::pinnedOptions($url), $url);
            $this->assertNull(SsrfGuard::pin($url), $url);
        }
    }
}
