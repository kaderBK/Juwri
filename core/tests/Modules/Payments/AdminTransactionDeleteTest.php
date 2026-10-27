<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\ActivityLog;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/** P6-246: paid payments are never hard-deleted by an admin — voided with a reason; unpaid ones go, logged. */
class AdminTransactionDeleteTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->bootAdminPanel();
    }

    private function tx(User $user, string $status, array $over = []): Transaction
    {
        return Transaction::forceCreate(array_merge([
            'user_id' => $user->id, 'status' => $status, 'amount' => 100, 'base_amount' => 100, 'currency_code' => 'USD',
            'product_name' => 'Plan', 'product_id' => 0, 'transaction_method' => 'membership', 'created_at' => now(),
        ], $over));
    }

    public function test_bulk_delete_refuses_paid_rows_without_a_reason_and_deletes_failed_ones_with_a_log(): void
    {
        $admin = $this->admin();
        $owner = $this->plainUser();
        $paid = $this->tx($owner, 'success');
        $refund = $this->tx($owner, 'success', ['transaction_method' => 'refund', 'amount' => -100, 'base_amount' => -100]);
        $failed = $this->tx($owner, 'failed');

        $res = $this->actingAs($admin)->postJson(route('admin.transactions.delete'), ['ids' => [$paid->id, $refund->id, $failed->id]]);

        $res->assertStatus(422)->assertJson(['success' => false]);
        $this->assertNotNull($paid->fresh(), 'paid row kept');
        $this->assertNull($paid->fresh()->voided_at);
        $this->assertNotNull($refund->fresh(), 'a refund (credit note) is kept');
        $this->assertNull(Transaction::find($failed->id), 'failed row deleted');
        $log = ActivityLog::where('action', 'admin.transaction_deleted')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, (int) $log->actor_user_id);
        $this->assertStringContainsString((string) $failed->id, json_encode($log->meta));
    }

    public function test_a_paid_row_with_a_reason_is_voided_not_deleted(): void
    {
        $admin = $this->admin();
        $paid = $this->tx($this->plainUser(), 'success');

        $this->actingAs($admin)->delete(route('admin.transactions.destroy', $paid->id), ['reason' => 'test payment by staff'])
            ->assertRedirect();

        $row = $paid->fresh();
        $this->assertNotNull($row, 'still on record');
        $this->assertNotNull($row->voided_at);
        $this->assertSame($admin->id, (int) $row->voided_by);
        $this->assertSame('test payment by staff', $row->void_reason);
        $this->assertTrue(ActivityLog::where('action', 'admin.transaction_voided')->exists());
    }

    public function test_single_delete_of_a_paid_row_without_a_reason_is_refused(): void
    {
        $paid = $this->tx($this->plainUser(), 'success');

        $this->actingAs($this->admin())->delete(route('admin.transactions.destroy', $paid->id))->assertRedirect();

        $this->assertNotNull($paid->fresh());
        $this->assertNull($paid->fresh()->voided_at);
    }

    public function test_only_admins_reach_delete(): void
    {
        $paid = $this->tx($this->plainUser(), 'failed');
        $this->assertAdminOnly('post', route('admin.transactions.delete'), ['ids' => [$paid->id]]);
        $this->assertNotNull($paid->fresh());
    }
}
