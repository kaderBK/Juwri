<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Fiscal\Services\Eta\EtaHttpClient;
use Juwri\Modules\Fiscal\Services\Http\FiscalHttpVerdicts;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraHttpClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-157: one test per row of fixtures/fiscal-http-verdicts.json, run through the real HTTP clients
 * (Http::fake). A sandbox surprise = change FiscalHttpVerdicts::TABLE + the matching fixture row.
 */
class FiscalHttpVerdictTableTest extends TestCase
{
    use RefreshDatabase; // the 409 row writes a platform alert: without it the row was committed on MySQL/PostgreSQL and leaked into later tests

    /** @return array<string, array{array<string, mixed>}> */
    public static function rows(): array
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/fixtures/fiscal-http-verdicts.json'), true, flags: JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['authority'].' '.$row['op'].' '.$row['status'].' '.$row['note']] = [$row];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row */
    #[DataProvider('rows')]
    public function test_row(array $row): void
    {
        Http::fake(['*' => Http::response($row['body'] === [] ? '' : $row['body'], $row['status'])]);

        try {
            $out = $this->call_client($row['authority'], $row['op']);
        } catch (\RuntimeException $e) {
            $this->assertSame('retry', $row['expected'], 'threw (retry) but table row expects '.$row['expected'].': '.$e->getMessage());

            return;
        }
        $this->assertNotSame('retry', $row['expected'], 'expected a throw (retry), got '.$out['status']);
        $this->assertSame($row['expected'], $out['status']);
        if ($row['authority'] === 'zatca' && $row['status'] === 409) {
            $this->assertTrue($out['response']['already_processed'] ?? false);
        }
    }

    public function test_every_table_entry_has_a_fixture_row(): void
    {
        $rows = array_map(fn ($r) => $r[0], self::rows());
        foreach (FiscalHttpVerdicts::TABLE as $authority => $map) {
            foreach (array_keys($map) as $key) {
                $hit = array_filter($rows, function ($r) use ($authority, $key) {
                    if ($r['authority'] !== $authority) {
                        return false;
                    }
                    $s = (int) $r['status'];
                    if (is_int($key)) {
                        return $s === $key;
                    }
                    if (array_key_exists($s, FiscalHttpVerdicts::TABLE[$authority])) {
                        return false;
                    }

                    return $key === 'other' ? ! in_array(intdiv($s, 100), [2, 4, 5], true) : $key === intdiv($s, 100).'xx';
                });
                $this->assertNotEmpty($hit, "no fixture row covers {$authority} {$key}");
            }
        }
    }

    public function test_unknown_authority_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FiscalHttpVerdicts::for('nope', 200);
    }

    /** @return array<string, mixed> */
    private function call_client(string $authority, string $op): array
    {
        $doc = ['xml' => '<x/>', 'uuid' => 'u-1', 'hash' => 'h'];

        return match ($authority) {
            'zatca' => $op === 'clear'
                ? new ZatcaHttpClient('https://gw.test/api')->clearInvoice('CERT', 's', $doc)
                : new ZatcaHttpClient('https://gw.test/api')->reportInvoice('CERT', 's', $doc),
            'eta' => new EtaHttpClient('https://id.eta.test', 'https://api.eta.test', 'https://lookup.eta.test')->submitReceipts('tok', [['uuid' => 'u-1']]),
            'jofotara' => new JofotaraHttpClient('https://backend.jofotara.test')->submit(['clientId' => 'c', 'secret' => 's'], $doc),
        };
    }
}
