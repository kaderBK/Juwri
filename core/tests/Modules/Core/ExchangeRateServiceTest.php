<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Models\ExchangeRate;
use Juwri\Core\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MC-30 — the FX pipeline: cross-via-USD rate lookup, staleness, and a refresh
 * command that keeps last-known rates on failure (never wipes).
 */
class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRate(string $quote, float $rate, ?string $fetchedAt = null): void
    {
        ExchangeRate::updateOrCreate(
            ['base' => 'USD', 'quote' => $quote, 'provider' => 'frankfurter'],
            ['rate' => $rate, 'fetched_at' => $fetchedAt ?? now()]
        );
        ExchangeRateService::flush();
    }

    public function test_rate_crosses_via_usd(): void
    {
        $this->seedRate('EUR', 0.90);
        $this->seedRate('OMR', 0.385);

        $this->assertSame(1.0, ExchangeRateService::rate('USD', 'USD'));
        $this->assertSame(1.0, ExchangeRateService::rate('EUR', 'EUR'));
        $this->assertEqualsWithDelta(0.90, ExchangeRateService::rate('USD', 'EUR'), 1e-9);
        $this->assertEqualsWithDelta(1 / 0.90, ExchangeRateService::rate('EUR', 'USD'), 1e-9);
        $this->assertEqualsWithDelta(0.385 / 0.90, ExchangeRateService::rate('EUR', 'OMR'), 1e-9);
        $this->assertNull(ExchangeRateService::rate('EUR', 'ZZZ'), 'unknown code → null');
    }

    public function test_is_stale_after_72h(): void
    {
        $this->seedRate('EUR', 0.9, now()->subHours(100)->toDateTimeString());
        $this->assertTrue(ExchangeRateService::isStale());

        $this->seedRate('EUR', 0.9, now()->subHour()->toDateTimeString());
        $this->assertFalse(ExchangeRateService::isStale());
    }

    public function test_is_stale_when_never_fetched(): void
    {
        $this->assertTrue(ExchangeRateService::isStale());
        $this->assertNull(ExchangeRateService::lastFetchedAt());
    }

    public function test_refresh_command_upserts_rates(): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(['base' => 'USD', 'rates' => ['EUR' => 0.9, 'OMR' => 0.385, 'JPY' => 150]], 200)]);

        $this->artisan('currency:refresh-rates')->assertSuccessful();

        $this->assertSame(3, ExchangeRate::count());
        $this->assertEqualsWithDelta(0.385, ExchangeRateService::rate('USD', 'OMR'), 1e-9);
    }

    public function test_refresh_failure_keeps_last_known(): void
    {
        $this->seedRate('EUR', 0.88);
        Http::fake(['api.frankfurter.dev/*' => Http::response('boom', 500)]);

        $this->artisan('currency:refresh-rates')->assertFailed();

        // The last-known rate survives a feed outage.
        $this->assertSame(1, ExchangeRate::count());
        $this->assertEqualsWithDelta(0.88, ExchangeRateService::rate('USD', 'EUR'), 1e-9);
    }
}
