<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\Expense;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Expense receipts are financial documents (invoices, bank slips). They now live on
 * the PRIVATE disk and are served only through an authed, owner-scoped route — not a
 * public /storage URL any URL-holder could fetch.
 */
class ExpenseReceiptSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(); // bypass feature/csrf gates; auth() still set by actingAs
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    public function test_receipt_download_is_owner_scoped_and_served_privately(): void
    {
        [$ownerA, $postA] = $this->store();
        [$ownerB] = $this->store();

        $path = 'expenses/'.$postA->id.'/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake receipt');
        $expense = Expense::create([
            'store_id' => $postA->id, 'amount' => 10, 'date' => '2026-08-11',
            'receipt_path' => $path, 'created_by' => $ownerA->id,
        ]);

        // The owner fetches their own receipt through the authed route (not /storage).
        $this->actingAs($ownerA, 'web')
            ->get(route('stores.books.expenses.receipt', $expense->id))
            ->assertOk();

        // Another owner cannot reach it (scoped to their own restaurants).
        $this->actingAs($ownerB, 'web')
            ->get(route('stores.books.expenses.receipt', $expense->id))
            ->assertNotFound();

        Storage::disk('local')->delete($path);
    }
}
