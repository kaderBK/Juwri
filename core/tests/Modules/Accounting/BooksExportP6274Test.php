<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Juwri\Modules\Accounting\Services\Accounting\GlReportService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-274: the accountant CSV export — every journal line in the range, balanced, and equal to the trial balance and
 * VAT report to the last fil.
 */
class BooksExportP6274Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_accounting_gl' => true]);
        $this->withoutMiddleware();
    }

    private function store(string $currency): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $s = Store::create(['user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        StoreOption::updateStoreOption($s->id, 'currency_code', $currency);
        ChartOfAccounts::ensure($s->id);

        return [$owner, $s];
    }

    private function sale(int $rid, int $n, float $gross, float $tax, string $date): void
    {
        JournalEngine::post($rid, [
            ['account' => JournalEngine::account($rid, '1110'), 'debit' => $gross, 'credit' => 0],
            ['account' => JournalEngine::account($rid, '4100'), 'debit' => 0, 'credit' => round($gross - $tax, 3)],
            ['account' => JournalEngine::account($rid, '2200'), 'debit' => 0, 'credit' => $tax, 'memo' => '=HYPERLINK("x")'],
        ], 'Manual', $n, 'sale', 'Sale '.$n, $date);
    }

    /** @return list<list<string>> */
    private function csv(User $owner, Store $s, string $type, array $q = []): array
    {
        $res = $this->actingAs($owner)->get(route('stores.books.export', ['store_id' => $s->id, 'type' => $type] + $q));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $body = ltrim($res->streamedContent(), "\u{FEFF}");

        return array_map('str_getcsv', array_values(array_filter(explode("\n", $body), fn ($l) => $l !== '')));
    }

    public function test_journals_export_has_every_line_in_range_and_balances_kwd(): void
    {
        [$owner, $s] = $this->store('KWD');
        $this->sale($s->id, 1, 10.125, 0.482, '2026-09-01');
        $this->sale($s->id, 2, 3.335, 0.159, '2026-09-30');
        $this->sale($s->id, 3, 99.999, 4.762, '2026-10-01'); // outside the range

        $rows = $this->csv($owner, $s, 'journals', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $head = array_shift($rows);
        $total = array_pop($rows);
        $this->assertSame('debit', $head[10]);
        $this->assertCount(6, $rows); // 2 journals × 3 lines, the October one left out
        $this->assertSame(['TOTAL', '13.460', '13.460'], [$total[0], $total[10], $total[11]]);
        $this->assertSame('0.482', $rows[2][11]);
        $this->assertStringStartsWith("'", $rows[2][12]); // formula neutralized

        $tb = (new GlReportService)->trialBalance($s->id, '2026-09-01', '2026-09-30');
        $this->assertSame(13.46, $tb['total_debit']);

        $tbRows = $this->csv($owner, $s, 'trial_balance', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertSame(['TOTAL', '13.460', '13.460'], [end($tbRows)[0], end($tbRows)[3], end($tbRows)[4]]);

        $vat = $this->csv($owner, $s, 'vat', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $out = array_values(array_filter($vat, fn ($r) => $r[0] === 'output_vat'))[0];
        $this->assertSame('0.641', $out[4]);
    }

    public function test_export_rejects_unknown_type_and_bad_range(): void
    {
        [$owner, $s] = $this->store('SAR');
        $this->actingAs($owner)->get(route('stores.books.export', ['store_id' => $s->id, 'type' => 'all']))->assertSessionHasErrors('type');
        $this->actingAs($owner)->get(route('stores.books.export', ['store_id' => $s->id, 'type' => 'journals', 'from' => '2026-09-30', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
    }

    public function test_export_is_tenant_scoped(): void
    {
        [$owner] = $this->store('SAR');
        [, $other] = $this->store('SAR');
        $this->sale($other->id, 1, 115, 15, '2026-09-01');
        $res = $this->actingAs($owner)->get(route('stores.books.export', ['store_id' => $other->id, 'type' => 'journals']));
        $this->assertContains($res->status(), [403, 404]);
    }
}
