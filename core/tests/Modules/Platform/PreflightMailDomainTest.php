<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Support\HostingPreflight;
use Tests\TestCase;

/** P6-113: preflight WARNs (never blocks) on missing SPF/DMARC and a sender domain unlike the app domain. DNS is faked. */
class PreflightMailDomainTest extends TestCase
{
    /** @param array<string, list<string>|null> $dns name => TXT records (null = lookup unavailable) */
    private function host(array $dns): HostingPreflight
    {
        return new class($dns) extends HostingPreflight
        {
            /** @param array<string, list<string>|null> $dns */
            public function __construct(private array $dns) {}

            protected function txtRecords(string $name): ?array
            {
                return array_key_exists($name, $this->dns) ? $this->dns[$name] : [];
            }

            protected function tcpConnect(string $host, int $port): ?string
            {
                return null;
            }

            protected function writable(string $path): bool
            {
                return true;
            }

            protected function schedulerAge(): ?int
            {
                return 10;
            }
        };
    }

    /** @return array<string, array{check: string, status: string, detail: string}> */
    private function rows(HostingPreflight $p): array
    {
        return collect($p->run('shared', '8.4.3'))->keyBy('check')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.shop.test', 'mail.mailers.smtp.port' => 587,
            'mail.from.address' => 'orders@shop.test', 'app.url' => 'https://shop.test',
            'queue.default' => 'database', 'cache.default' => 'array', 'session.driver' => 'array',
        ]);
    }

    public function test_spf_and_dmarc_present_is_ok(): void
    {
        $rows = $this->rows($this->host([
            'shop.test' => ['v=spf1 +a +mx ~all'],
            '_dmarc.shop.test' => ['v=DMARC1; p=none'],
        ]));
        $this->assertSame('ok', $rows['Mail records']['status']);
        $this->assertSame('ok', $rows['Mail sender domain']['status']);
    }

    public function test_missing_records_warn_and_never_block(): void
    {
        $all = array_values($this->rows($this->host(['shop.test' => ['google-site-verification=abc']])));
        $rows = collect($all)->keyBy('check');
        $this->assertSame('warn', $rows['Mail records']['status']);
        $this->assertStringContainsString('SPF or DMARC', $rows['Mail records']['detail']);
        $this->assertFalse(HostingPreflight::hasBlockers(array_values(array_filter($all, fn ($r) => str_starts_with($r['check'], 'Mail records')))));
    }

    public function test_only_dmarc_missing_names_dmarc(): void
    {
        $rows = $this->rows($this->host(['shop.test' => ['v=spf1 -all']]));
        $this->assertSame('warn', $rows['Mail records']['status']);
        $this->assertStringContainsString('No DMARC record', $rows['Mail records']['detail']);
    }

    public function test_offline_dns_is_skipped_with_a_warn(): void
    {
        $rows = $this->rows($this->host(['shop.test' => null, '_dmarc.shop.test' => null]));
        $this->assertSame('warn', $rows['Mail records']['status']);
        $this->assertStringContainsString('not checked', $rows['Mail records']['detail']);
    }

    public function test_sender_domain_different_from_app_domain_warns_but_subdomain_is_fine(): void
    {
        config(['mail.from.address' => 'hi@other.test']);
        $rows = $this->rows($this->host([]));
        $this->assertSame('warn', $rows['Mail sender domain']['status']);
        $this->assertStringContainsString('differs from the app domain shop.test', $rows['Mail sender domain']['detail']);

        config(['mail.from.address' => 'hi@mail.shop.test']);
        $this->assertSame('ok', $this->rows($this->host([]))['Mail sender domain']['status']);
    }

    public function test_log_mailer_skips_the_domain_checks(): void
    {
        config(['mail.default' => 'log']);
        $rows = $this->rows($this->host([]));
        $this->assertArrayNotHasKey('Mail records', $rows);
        $this->assertArrayNotHasKey('Mail sender domain', $rows);
    }
}
