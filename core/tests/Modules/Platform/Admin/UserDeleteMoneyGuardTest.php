<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Admin;

use App\Jobs\DeleteUsersJob;
use App\Models\ActivityLog;
use App\Models\Upgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * P6-245: admin user delete cancels a paying account at the gateway first (a failed cancel keeps the account), skips
 * accounts still holding money unless forced, and logs every deleted account with the admin.
 */
class UserDeleteMoneyGuardTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->bootAdminPanel();
        Cache::flush();
    }

    public function test_paid_user_is_cancelled_at_the_gateway_then_deleted_and_logged(): void
    {
        Http::fake(['*paddle.com/*' => Http::response(['data' => []], 200)]);
        $admin = $this->admin();
        $paying = $this->plainUser();
        Upgrade::create(['user_id' => $paying->id, 'sub_id' => 1, 'pay_mode' => 'recurring', 'unique_id' => 'paddle###sub_ok']);

        $this->actingAs($admin)->postJson(route('admin.users.delete'), ['ids' => [$paying->id]])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/subscriptions/sub_ok/cancel'));
        $this->assertNull(User::find($paying->id));
        $log = ActivityLog::where('action', 'admin.user_deleted')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, (int) $log->actor_user_id);
    }

    public function test_a_failed_gateway_cancel_keeps_the_account_even_when_forced(): void
    {
        Http::fake(['*paddle.com/*' => Http::response(['error' => 'down'], 500)]);
        $paying = $this->plainUser();
        Upgrade::create(['user_id' => $paying->id, 'sub_id' => 1, 'pay_mode' => 'recurring', 'unique_id' => 'paddle###sub_bad']);

        $this->actingAs($this->admin())->postJson(route('admin.users.delete'), ['ids' => [$paying->id], 'force' => 1])
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertNotNull(User::find($paying->id));
        $this->assertTrue(PlatformAlert::where('kind', 'payment')->exists());
    }

    public function test_wallet_balance_skips_the_account_unless_forced(): void
    {
        $admin = $this->admin();
        $rich = $this->plainUser();
        $plain = $this->plainUser();
        WalletService::credit($rich, 12.5, 'seed', 'seed-rich');

        $res = $this->actingAs($admin)->postJson(route('admin.users.delete'), ['ids' => [$rich->id, $plain->id]]);
        $res->assertOk()->assertJsonPath('skipped.'.$rich->id, 'wallet balance 12.5 '.WalletService::currency());
        $this->assertNotNull(User::find($rich->id));
        $this->assertNull(User::find($plain->id));

        $this->actingAs($admin)->postJson(route('admin.users.delete'), ['ids' => [$rich->id], 'force' => 1])->assertOk();
        $this->assertNull(User::find($rich->id));
    }

    public function test_the_bulk_filtered_job_skips_money_holders_and_reports_them(): void
    {
        $rich = $this->plainUser();
        $plain = $this->plainUser();
        WalletService::credit($rich, 5, 'seed', 'seed-rich2');

        $ids = [$rich->id, $plain->id];
        $token = DeleteUsersJob::tokenFor($ids);
        DeleteUsersJob::markQueued($token, 2);
        DeleteUsersJob::dispatchSync($token, $ids, 99);

        $this->assertNotNull(User::find($rich->id));
        $this->assertNull(User::find($plain->id));
        $this->assertStringContainsString('1 kept', DeleteUsersJob::status($token)['message']);
        $this->assertTrue(ActivityLog::where('action', 'admin.user_delete_skipped')->exists());
    }

    public function test_a_user_with_paid_payments_is_anonymised_and_the_payments_kept(): void
    {
        $admin = $this->admin();
        $payer = $this->plainUser();
        $other = $this->plainUser();
        $paid = \Juwri\Modules\Payments\Models\Transaction::forceCreate(['user_id' => $payer->id, 'status' => 'success', 'amount' => 10, 'base_amount' => 10, 'currency_code' => 'USD', 'product_name' => 'P', 'product_id' => 0, 'created_at' => now()]);
        $failed = \Juwri\Modules\Payments\Models\Transaction::forceCreate(['user_id' => $other->id, 'status' => 'failed', 'amount' => 10, 'base_amount' => 10, 'currency_code' => 'USD', 'product_name' => 'P', 'product_id' => 0, 'created_at' => now()]);

        $this->actingAs($admin)->postJson(route('admin.users.delete'), ['ids' => [$payer->id, $other->id]])->assertOk();

        $kept = User::find($payer->id);
        $this->assertNotNull($kept, 'anonymised, not deleted');
        $this->assertSame('Deleted user', $kept->name);
        $this->assertStringEndsWith('@deleted.invalid', $kept->email);
        $this->assertSame($payer->id, (int) $paid->fresh()->user_id);
        $this->assertNull(User::find($other->id));
        $row = $failed->fresh();
        $this->assertNotNull($row, 'the FK no longer cascades');
        $this->assertNull($row->user_id);
        $this->assertSame(64, strlen((string) $row->former_user_ref));
    }
}
