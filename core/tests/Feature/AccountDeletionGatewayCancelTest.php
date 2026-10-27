<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Upgrade;
use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\User;
use Mockery;
use Tests\TestCase;

/**
 * P6-244: closing an account cancels the subscription at the gateway first. A failed cancel keeps the local
 * subscription live (the gateway still bills it), alerts the admin, is retried by the daily purge run, and blocks
 * the purge until the gateway cancel succeeds.
 */
class AccountDeletionGatewayCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_gateway_cancel_keeps_the_subscription_alerts_and_blocks_the_purge_until_it_succeeds(): void
    {
        Mail::fake();
        Http::fake(['*paddle.com/*' => Http::sequence()
            ->push(['error' => 'down'], 500)   // close
            ->push(['error' => 'down'], 500)   // purge run 1: retry
            ->push(['error' => 'down'], 500)   // purge run 1: purge's own attempt
            ->push(['data' => []], 200)]);     // purge run 2: retry succeeds

        $subs = Mockery::mock(Subscriptions::class)->shouldIgnoreMissing();
        $subs->shouldReceive('expireLive')->once();
        $this->app->instance(Subscriptions::class, $subs);

        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        Upgrade::create(['user_id' => $owner->id, 'sub_id' => 1, 'pay_mode' => 'recurring', 'unique_id' => 'paddle###sub_live']);

        app(AccountDeletionService::class)->request($owner);

        $this->assertSame('paddle###sub_live', Upgrade::where('user_id', $owner->id)->value('unique_id'), 'gateway id kept = cancel pending');
        $this->assertTrue(PlatformAlert::where('kind', 'payment')->where('message', 'like', '%could not be cancelled%')->exists());
        $this->assertTrue(ActivityLog::where('action', 'account.gateway_cancel_failed')->exists());

        AccountDeletion::query()->update(['purge_after' => now()->subDay()]);

        $this->artisan('accounts:purge-deleted')->assertSuccessful();
        $this->assertSame(AccountDeletion::PENDING, AccountDeletion::sole()->status, 'purge blocked while the gateway bills');
        $this->assertNotNull(User::find($owner->id));

        $this->artisan('accounts:purge-deleted')->assertSuccessful();
        $this->assertSame(AccountDeletion::PURGED, AccountDeletion::sole()->status, 'purged once the retry cancelled it');
    }
}
