<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPlans;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv("THEME_NAME=juwri");
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        app()->setLocale('en');

        // Scaffolding for view shared settings/plans
        $settings = new class([
            'theme_color' => '#111111',
            'site_title' => 'juwri',
            'meta_description' => 'Test description',
            'social_share_image' => 'default.png',
            'userlangsel' => 0,
            'include_language_code' => 0,
            'site_favicon' => 'favicon.png',
        ]) {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };

        $plan = TestPlans::create([
            'name' => 'Test Plan',
            'description' => 'Test plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'allow_viewonly' => '1',
                'allow_pro' => '1',
                'restaurant_limit' => 999,
            ],
            'status' => 1,
        ]);

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'plan' => $plan,
        ]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    public function test_credit_adds_balance_and_records_transaction(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->assertEquals(0, WalletService::balanceFor($user));

        $newBalance = WalletService::credit($user, 100.50, 'Referral bonus', 'ref_123');

        $this->assertEquals(100.50, $newBalance);
        $this->assertEquals(100.50, WalletService::balanceFor($user));

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'amount_minor' => 10050, // P1-05: minor units
            'balance_after_minor' => 10050,
            'type' => 'credit',
            'reason' => 'Referral bonus',
            'ref' => 'ref_123'
        ]);
    }

    public function test_debit_deducts_balance_and_records_transaction(): void
    {
        $user = User::factory()->create(['status' => 1]);

        WalletService::credit($user, 150.00, 'Starting deposit');
        $newBalance = WalletService::debit($user, 50.00, 'Purchase item');

        $this->assertEquals(100.00, $newBalance);
        $this->assertEquals(100.00, WalletService::balanceFor($user));

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'amount_minor' => -5000, // P1-05: minor units
            'balance_after_minor' => 10000,
            'type' => 'debit',
            'reason' => 'Purchase item'
        ]);
    }

    public function test_debit_throws_exception_on_insufficient_funds(): void
    {
        $user = User::factory()->create(['status' => 1]);

        WalletService::credit($user, 20.00, 'Small deposit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient wallet balance');

        WalletService::debit($user, 50.00, 'Too expensive');
    }

    public function test_two_debits_exceeding_balance_only_one_succeeds(): void
    {
        // P0-2 (B1): the funds check reads the balance under lockForUpdate, so
        // two debits that together exceed the balance cannot both post. (On
        // MySQL the row lock serializes true concurrency; here we assert the
        // guard's outcome sequentially — exactly one debit lands, no overdraft.)
        $user = User::factory()->create(['status' => 1]);
        WalletService::credit($user, 100.00, 'Deposit', 'seed');

        $ok = 0;
        $rejected = 0;
        foreach (['d1', 'd2'] as $ref) {
            try {
                WalletService::debit($user, 60.00, 'Spend', $ref);
                $ok++;
            } catch (\RuntimeException $e) {
                $rejected++;
            }
        }

        $this->assertSame(1, $ok, 'exactly one 60-debit succeeds against a 100 balance');
        $this->assertSame(1, $rejected, 'the second is rejected for insufficient funds');
        $this->assertEquals(40.00, WalletService::balanceFor($user), 'balance never goes negative');
        $this->assertSame(1, WalletTransaction::where('user_id', $user->id)->where('type', 'debit')->count());
    }

    public function test_credit_and_debit_are_idempotent_with_reference(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $b1 = WalletService::credit($user, 100.00, 'First time', 'ref_idemp_1');
        $b2 = WalletService::credit($user, 100.00, 'Duplicate request', 'ref_idemp_1');

        $this->assertEquals(100.00, $b1);
        $this->assertEquals(100.00, $b2);
        $this->assertEquals(100.00, WalletService::balanceFor($user));

        // Test debit idempotency
        $d1 = WalletService::debit($user, 30.00, 'First deduction', 'ref_idemp_2');
        $d2 = WalletService::debit($user, 30.00, 'Duplicate deduction', 'ref_idemp_2');

        $this->assertEquals(70.00, $d1);
        $this->assertEquals(70.00, $d2);
        $this->assertEquals(70.00, WalletService::balanceFor($user));
    }

    public function test_wallet_dashboard_renders_balance(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $user = User::factory()->create(['status' => 1]);
        WalletService::credit($user, 250.00, 'Initial load');

        // Unauthenticated redirect
        $this->get(route('wallet.index'))->assertRedirect();

        // Authenticated access
        session(['template' => 'juwri']);
        $response = $this->actingAs($user)->get(route('wallet.index'));
        $response->assertStatus(200);
        $response->assertSee('250');
    }

    public function test_wallet_movements_mirror_into_the_ledger(): void
    {
        // P1-4: the platform wallet dual-writes to the unified ledger. The
        // merchant ledger account must always equal the wallet balance, and the
        // move must be balanced by the platform clearing account (zero-sum).
        $user = User::factory()->create(['status' => 1]);

        WalletService::credit($user, 100.00, 'topup', 'c1');
        WalletService::debit($user, 30.00, 'spend', 'd1');

        $merchant = LedgerService::accountFor('merchant', $user->id, 'merchant');
        $clearing = LedgerService::accountFor('platform', 0, 'platform');

        $this->assertEqualsWithDelta(70.00, WalletService::balanceFor($user), 0.001);
        $this->assertEqualsWithDelta(70.00, LedgerService::balanceFor($merchant), 0.001, 'ledger mirror equals wallet balance');
        $this->assertEqualsWithDelta(-70.00, LedgerService::balanceFor($clearing), 0.001, 'clearing is the mirror counterparty');
    }

    public function test_mirror_does_not_double_apply_on_idempotent_replay(): void
    {
        $user = User::factory()->create(['status' => 1]);
        WalletService::credit($user, 50.00, 'topup', 'once');
        WalletService::credit($user, 50.00, 'topup dup', 'once'); // replay

        $merchant = LedgerService::accountFor('merchant', $user->id, 'merchant');
        $this->assertEqualsWithDelta(50.00, LedgerService::balanceFor($merchant), 0.001);
    }

    public function test_multi_tenant_isolation(): void
    {
        $userA = User::factory()->create(['status' => 1]);
        $userB = User::factory()->create(['status' => 1]);

        WalletService::credit($userA, 100.00, 'User A money', 'ref_a');
        WalletService::credit($userB, 200.00, 'User B money', 'ref_b');

        // Balance A must be 100
        $this->assertEquals(100.00, WalletService::balanceFor($userA));
        // Balance B must be 200
        $this->assertEquals(200.00, WalletService::balanceFor($userB));

        // User A cannot view User B's ledger (WalletService::ledgerFor only queries user_id)
        $ledgerA = WalletService::ledgerFor($userA);
        $this->assertCount(1, $ledgerA);
        $this->assertEquals($userA->id, $ledgerA[0]->user_id);
    }
}
