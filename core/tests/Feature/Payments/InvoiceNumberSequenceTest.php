<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * Invoice numbers continue this year's series from the highest number already issued (port of old-platform
 * d8fc8224a): gaps, deleted rows, old rows and reseller numbers never make a new invoice reuse a taken number,
 * and the series is platform-wide (one unique index) even inside a tenant request.
 */
class InvoiceNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.invoice_nr_prefix' => 'YINV - ']);
    }

    protected function tearDown(): void
    {
        TenantContext::current()->clear();
        parent::tearDown();
    }

    private function number(int $n, ?int $year = null): string
    {
        return 'YINV - '.($year ?? (int) date('Y')).'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public function test_first_invoice_of_the_year_is_number_one_and_is_stored(): void
    {
        $tx = Transaction::factory()->settled()->create();

        $this->assertSame($this->number(1), $tx->ensureInvoiceNumber());
        $this->assertSame($this->number(1), $tx->fresh()->invoice_number);
    }

    public function test_continues_after_the_highest_issued_number_not_the_row_count(): void
    {
        // An old transaction holding this year's number 10, gaps, reseller "R-…" numbers and last year's series.
        Transaction::factory()->settled()->create(['invoice_number' => $this->number(10), 'created_at' => now()->subYears(3)]);
        foreach ([1, 2, 3, 8, 9] as $n) {
            Transaction::factory()->settled()->create(['invoice_number' => $this->number($n)]);
        }
        foreach (['R-TTE2DUQSO8', 'R-2ET6CKVIQI'] as $reseller) {
            Transaction::factory()->settled()->create(['invoice_number' => $reseller]);
        }
        Transaction::factory()->settled()->create(['invoice_number' => $this->number(50, (int) date('Y') - 1), 'created_at' => now()->subYear()]);
        Transaction::factory()->settled()->create(['invoice_number' => 'OTHER - '.date('Y').'-00099']);

        $tx = Transaction::factory()->settled()->create();

        $this->assertSame($this->number(11), $tx->ensureInvoiceNumber());
    }

    public function test_deleting_the_latest_invoiced_row_never_reuses_a_number_below_the_highest(): void
    {
        $first = Transaction::factory()->settled()->create();
        $second = Transaction::factory()->settled()->create();
        $third = Transaction::factory()->settled()->create();
        $this->assertSame($this->number(1), $first->ensureInvoiceNumber());
        $this->assertSame($this->number(2), $second->ensureInvoiceNumber());
        $this->assertSame($this->number(3), $third->ensureInvoiceNumber());

        // An earlier row going away must not make the next invoice collide with number 3.
        $first->delete();
        $next = Transaction::factory()->settled()->create();
        $this->assertSame($this->number(4), $next->ensureInvoiceNumber());

        // The latest invoiced row deleted: the series continues from the highest still issued.
        $next->delete();
        $after = Transaction::factory()->settled()->create();
        $this->assertSame($this->number(4), $after->ensureInvoiceNumber());
        $this->assertSame(1, Transaction::query()->where('invoice_number', $this->number(4))->count());
    }

    public function test_an_existing_number_is_kept(): void
    {
        $tx = Transaction::factory()->settled()->create(['invoice_number' => $this->number(3)]);

        $this->assertSame($this->number(3), $tx->ensureInvoiceNumber());
        $this->assertSame($this->number(3), $tx->fresh()->ensureInvoiceNumber());
    }

    public function test_the_series_is_platform_wide_inside_a_tenant_request(): void
    {
        $fx = new ApiFixtures;
        $a = $fx->store($fx->owner(), 'inv-a', 'Store A', 'retail');
        $b = $fx->store($fx->owner(), 'inv-b', 'Store B', 'retail');
        $this->assertNotSame((int) $a->account_id, (int) $b->account_id);

        Transaction::factory()->settled()->create(['user_id' => $b->user_id, 'store_id' => $b->id, 'invoice_number' => $this->number(7)]);
        $mine = Transaction::factory()->settled()->create(['user_id' => $a->user_id, 'store_id' => $a->id]);

        TenantContext::current()->actAs((int) $a->account_id);
        $this->assertNull(Transaction::query()->where('invoice_number', $this->number(7))->first(), 'the other account is hidden');

        $this->assertSame($this->number(8), Transaction::query()->findOrFail($mine->id)->ensureInvoiceNumber());
    }
}
