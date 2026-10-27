<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/** BL-038: checkout rows kept only as bookkeeping are not payments, so the admin's list does not show them. */
class CheckoutBookkeepingListTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->bootAdminPanel();
    }

    public function test_bookkeeping_checkout_rows_are_not_listed_as_payments(): void
    {
        $owner = $this->plainUser();
        $rows = ['PaidPaymentRow' => Transaction::STATUS_SUCCESS, 'OpenCheckoutRow' => null];
        foreach (Transaction::CHECKOUT_BOOKKEEPING as $status) {
            $rows['Bookkeeping'.str_replace('_', '', ucwords($status, '_')).'Row'] = $status;
        }
        foreach ($rows as $description => $status) {
            Transaction::forceCreate(['user_id' => $owner->id, 'status' => $status, 'amount' => 100, 'base_amount' => 100, 'currency_code' => 'USD',
                'product_name' => 'Plan', 'product_id' => 0, 'transaction_method' => 'membership', 'transaction_description' => $description,
                'created_at' => now()]);
        }

        $response = $this->actingAs($this->admin())->get(route('admin.transactions.index'))->assertOk();

        $response->assertViewHas('transactions', function ($transactions): bool {
            $listed = collect($transactions->items())->pluck('transaction_description')->sort()->values()->all();

            return $listed === ['OpenCheckoutRow', 'PaidPaymentRow'];
        });
    }
}
