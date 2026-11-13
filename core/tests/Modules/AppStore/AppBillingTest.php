<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use App\Models\AppInstallation;
use App\Services\AppInstallService;
use App\Services\AppSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Services\AppBillingService;
use Juwri\Modules\Auth\Models\OAuthClient as Client;
use Juwri\Modules\Payments\Services\WalletService;
use RuntimeException;
use Tests\TestCase;

/**
 * P3-4: per-app billing on the WalletService choke point — recurring install
 * charge + scheduled renewal (suspend on empty wallet, access auto-blocked),
 * and metered usage via the partner OAuth endpoint (idempotent by ref).
 */
class AppBillingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(float $balance = 0): User
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        if ($balance > 0) {
            WalletService::credit($user, $balance, 'test seed');
        }

        return $user;
    }

    private function makeApp(array $overrides = []): App
    {
        return App::create(array_merge([
            'name' => 'Paid App', 'slug' => 'paid-app-'.uniqid(),
            'scopes' => ['menu:read'],
            'status' => 'approved',
            'billing_type' => 'recurring',
            'price' => 10.00,
            'billing_period_days' => 30,
        ], $overrides));
    }

    public function test_free_apps_install_without_any_charge(): void
    {
        $owner = $this->makeOwner();
        $app = $this->makeApp(['billing_type' => 'free', 'price' => 0]);

        $installation = new AppInstallService()->install($app, $owner);

        $this->assertSame('active', $installation->status);
        $this->assertSame(0.0, WalletService::balanceFor($owner));
        $this->assertNull($installation->current_period_ends_at);
    }

    public function test_recurring_install_charges_the_wallet_and_sets_the_period(): void
    {
        $owner = $this->makeOwner(25);
        $app = $this->makeApp();

        $installation = new AppInstallService()->install($app, $owner);

        $this->assertSame(15.0, WalletService::balanceFor($owner));
        $this->assertNotNull($installation->fresh()->current_period_ends_at);
        $this->assertTrue($installation->fresh()->current_period_ends_at->gt(now()->addDays(29)));
    }

    public function test_insufficient_funds_aborts_the_install_cleanly(): void
    {
        $owner = $this->makeOwner(3);
        $app = $this->makeApp();

        try {
            new AppInstallService()->install($app, $owner);
            $this->fail('install should have failed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment failed', $e->getMessage());
        }

        $this->assertSame(3.0, WalletService::balanceFor($owner), 'no partial charge');
        $this->assertDatabaseCount('app_installations', 0);
    }

    public function test_renewal_charges_or_suspends_and_suspension_blocks_session_tokens(): void
    {
        $client = Client::create([
            'name' => 'Paid', 'secret' => 'paid-secret-paid-secret-000000000000', 'provider' => 'users',
            'redirect' => 'https://paid.test/cb',
            'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);
        $rich = $this->makeOwner(50);
        $poor = $this->makeOwner(10); // covers install, not renewal
        $app = $this->makeApp(['oauth_client_id' => $client->id]);
        $service = new AppInstallService;

        $instRich = $service->install($app, $rich);
        $instPoor = $service->install($app, $poor);

        // Force both periods to be due.
        AppInstallation::query()->update(['current_period_ends_at' => now()->subDay()]);

        $result = new AppBillingService()->renewDue();

        $this->assertSame(1, $result['renewed']);
        // P4-4 (feature-platform) + P1-23 part 7 (Terms 6.4): a failed renewal goes past_due and keeps access;
        // suspension needs 14 days overdue + 7 days' written notice.
        $this->assertSame(0, $result['suspended']);
        $this->assertSame(30.0, WalletService::balanceFor($rich)); // 50 - 10 - 10
        $this->assertSame('active', $instRich->fresh()->status);
        $this->assertSame('past_due', $instPoor->fresh()->status);
        $this->assertNotNull($instPoor->fresh()->grace_period_ends_at);

        // A past grace date alone no longer suspends (no notice yet: the old 3-day rule is gone).
        $instPoor->fresh()->update(['grace_period_ends_at' => now()->subMinute()]);
        $this->assertSame(0, new AppBillingService()->renewDue()['suspended']);
        $this->assertSame('past_due', $instPoor->fresh()->status);

        // 21 days overdue with the notice 8 days ago → suspended on the next tick.
        $instPoor->fresh()->update(['current_period_ends_at' => now()->subDays(22), 'suspension_notice_at' => now()->subDays(8), 'grace_period_ends_at' => now()->subDay()]);
        $result2 = new AppBillingService()->renewDue();
        $this->assertSame(1, $result2['suspended']);
        $this->assertSame('suspended', $instPoor->fresh()->status);

        // Suspension blocks embedded access: the middleware needs an ACTIVE install.
        Route::middleware('auth.app-session')->get('/_test/paid-api', fn () => ['ok' => true]);
        $token = AppSessionToken::mint($app, $poor);
        $this->getJson('/_test/paid-api', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();

        // Top up + reinstall reactivates (and charges the new period).
        WalletService::credit($poor, 20, 'top up');
        $service->install($app, $poor);
        $this->assertSame('active', $instPoor->fresh()->status);
    }

    /**
     * An empty wallet is the expected dunning path, not a fault: the failed
     * renewal is a WARNING, never a reported error.
     */
    public function test_an_empty_wallet_renewal_is_logged_as_a_warning_and_not_reported(): void
    {
        Mail::fake();
        $owner = $this->makeOwner(10); // covers the install, nothing left for the renewal
        $app = $this->makeApp();
        $installation = new AppInstallService()->install($app, $owner);
        $this->assertSame(0.0, WalletService::balanceFor($owner), 'the install used the whole balance');

        // Force the period due.
        AppInstallation::query()->update(['current_period_ends_at' => now()->subDay()]);

        Exceptions::fake();
        Log::spy();

        $result = new AppBillingService()->renewDue();

        $this->assertSame(0, $result['renewed']);
        $this->assertSame(0, $result['suspended']);
        $this->assertSame('past_due', $installation->fresh()->status, 'the dunning path still runs');

        Exceptions::assertNothingReported();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'app renewal: Insufficient wallet balance')
                && ($context['installation_id'] ?? null) === $installation->id)
            ->once();
    }

    public function test_partner_usage_endpoint_meters_idempotently(): void
    {
        $this->withoutMiddleware([PreventRequestForgery::class]);
        $this->artisan('passport:keys', ['--force' => true]);

        $secret = 'meter-secret-meter-secret-00000000';
        $client = Client::create([
            'name' => 'Meter', 'secret' => $secret, 'provider' => 'users',
            'redirect' => 'https://meter.test/cb',
            'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);
        $owner = $this->makeOwner(20);
        $app = $this->makeApp([
            'oauth_client_id' => $client->id,
            'billing_type' => 'usage', 'price' => 0,
            'usage_unit' => 'sms', 'usage_price' => 0.05,
        ]);
        new AppInstallService()->install($app, $owner);

        // Real P3-1 flow: consent → approve → code → token (server-to-server credential).
        $html = $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'https://meter.test/cb',
            'response_type' => 'code', 'scope' => 'menu:read', 'state' => 's',
        ]))->assertOk()->getContent();
        preg_match('/name="auth_token" value="([^"]+)"/', $html, $m);
        $redirect = $this->actingAs($owner)->post('/oauth/authorize', [
            'state' => 's', 'client_id' => $client->id, 'auth_token' => $m[1],
        ])->headers->get('location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
        $bearer = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $secret, 'redirect_uri' => 'https://meter.test/cb',
            'code' => $q['code'],
        ])->json('access_token');

        $auth = ['Authorization' => 'Bearer '.$bearer];
        $first = $this->postJson('/api/app/usage', ['quantity' => 40, 'ref' => 'evt_1'], $auth)
            ->assertCreated()->json();
        $this->assertSame(2.0, (float) $first['charged']); // 40 × 0.05

        // Retry with the same ref → no double charge.
        $this->postJson('/api/app/usage', ['quantity' => 40, 'ref' => 'evt_1'], $auth)->assertCreated();
        $this->assertSame(18.0, WalletService::balanceFor($owner));
        $this->assertDatabaseCount('app_usage_records', 1);

        // Wallet exhaustion → 402.
        $this->postJson('/api/app/usage', ['quantity' => 10000, 'ref' => 'evt_2'], $auth)->assertStatus(402);
    }
}
