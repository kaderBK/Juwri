<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * P6-50: docker/env.example (what self-hosters copy to docker/.env) must satisfy the keys of
 * deploy/PROD-HARDENING-CHECKLIST.md sections 1-5, and compose.prod.yml must keep the Redis profile and loopback port.
 */
class DockerEnvProductionDefaultsTest extends TestCase
{
    /** @return array<string, string> active (uncommented) KEY=value lines */
    private function env(): array
    {
        $out = [];
        foreach (file(dirname(base_path()).'/docker/env.example', FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }

        return $out;
    }

    public function test_env_example_matches_checklist_sections_1_to_5(): void
    {
        $e = $this->env();

        $this->assertSame('production', $e['APP_ENV']);
        $this->assertSame('false', $e['APP_DEBUG']);
        $this->assertArrayHasKey('APP_KEY', $e);
        $this->assertArrayHasKey('APP_URL', $e);
        $this->assertSame('false', $e['TOKEN_QUERY_FALLBACK']);
        $this->assertSame('true', $e['PASSWORD_POLICY_STRICT']);
        $this->assertSame('true', $e['HS_VERIFY_SIGNATURES']);
        $this->assertSame('false', $e['SMS_INBOUND_ALLOW_UNSIGNED']);
        $this->assertArrayHasKey('HEALTHZ_TOKEN', $e);
        $this->assertSame('', $e['HEALTHZ_TOKEN'], 'no shipped token value');
        $this->assertArrayNotHasKey('MCP_ALLOWED_ORIGINS', $e, 'blank unless a browser MCP client needs it');
        $this->assertSame('true', $e['SESSION_SECURE_COOKIE']);

        $this->assertNotSame('', $e['CORS_ALLOWED_ORIGINS']);
        $this->assertStringNotContainsString('*', $e['CORS_ALLOWED_ORIGINS']);
        $this->assertStringNotContainsString('*', $e['TRUSTED_PROXIES']);
        $this->assertNotSame('none', $e['TRUSTED_PROXIES'], 'proxy mode must trust something (ops:preflight trusted_proxies)');
        $this->assertSame(':8080', $e['SERVER_NAME']);

        foreach (['CSP_ENABLED', 'CSP_ENFORCE', 'CSP_ENFORCE_DASHBOARD'] as $k) {
            $this->assertNotSame('false', $e[$k] ?? 'true', "$k must not be false");
        }
        foreach (array_keys($e) as $k) {
            $this->assertStringStartsNotWith('ABUSE_MODE', $k, 'abuse guards default to enforce in production; leave unset');
        }
    }

    public function test_env_example_perf_and_redis_profile(): void
    {
        $e = $this->env();
        $this->assertSame('true', $e['STOREFRONT_FULLPAGE_CACHE']);
        $this->assertSame('300', $e['STOREFRONT_FULLPAGE_CACHE_TTL']);
        // the env keys must be the ones config/perf.php really reads
        $perf = file_get_contents(config_path('perf.php'));
        $this->assertStringContainsString("env('STOREFRONT_FULLPAGE_CACHE', false)", $perf);
        $this->assertStringContainsString("env('STOREFRONT_FULLPAGE_CACHE_TTL'", $perf);

        $raw = file_get_contents(dirname(base_path()).'/docker/env.example');
        foreach (['QUEUE_CONNECTION=redis', 'CACHE_DRIVER=redis', 'SESSION_DRIVER=redis', 'REDIS_HOST=redis', 'REDIS_PASSWORD='] as $line) {
            $this->assertMatchesRegularExpression('/^# '.preg_quote($line, '/').'/m', $raw, "documented production profile line $line");
        }
        $this->assertSame('127.0.0.1', $e['APP_BIND']);
    }

    public function test_compose_prod_has_redis_profile_and_loopback_port(): void
    {
        $c = file_get_contents(dirname(base_path()).'/docker/compose.prod.yml');
        $this->assertStringContainsString('${APP_BIND:-127.0.0.1}:${APP_PORT:-8080}:8080', $c);
        $this->assertStringNotContainsString('"8080:8080"', $c);
        $this->assertMatchesRegularExpression('/^  redis:\n(?:.*\n)*?    profiles: \["redis"\]/m', $c);
        $this->assertDoesNotMatchRegularExpression('/^  redis:\n(?:    .*\n)*?    ports:/m', $c, 'redis must not publish a host port');
    }
}
