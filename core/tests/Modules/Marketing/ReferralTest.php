<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Models\Referral;
use App\Models\ReferralConversion;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5], 'status' => 1]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /** forUser() creates referral with unique code on first call */
    public function test_for_user_creates_referral(): void
    {
        $user = $this->makeUser();
        $ref = Referral::forUser($user);

        $this->assertDatabaseHas('referrals', ['user_id' => $user->id]);
        $this->assertNotEmpty($ref->code);
        $this->assertEquals(8, strlen($ref->code));
    }

    /** forUser() is idempotent — returns same row on second call */
    public function test_for_user_idempotent(): void
    {
        $user = $this->makeUser();
        $r1 = Referral::forUser($user);
        $r2 = Referral::forUser($user);
        $this->assertEquals($r1->id, $r2->id);
        $this->assertEquals(1, Referral::where('user_id', $user->id)->count());
    }

    /** Referral index page loads */
    public function test_referral_index_loads(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get(route('referral.index'))->assertOk();
    }

    /** Admin can approve a pending conversion */
    public function test_admin_approves_conversion(): void
    {
        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
        $referrer = $this->makeUser();
        $newbie   = $this->makeUser();

        $referral = Referral::forUser($referrer);
        $conversion = ReferralConversion::create([
            'referral_id'   => $referral->id,
            'new_user_id'   => $newbie->id,
            'status'        => 'pending',
            'reward_type'   => 'credit',
            'reward_amount' => 10.00,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.referrals.approve', $conversion->id))
            ->assertRedirect();

        // P6 (6.2): approve now pays out — status 'paid' + referrer wallet credited.
        $this->assertDatabaseHas('referral_conversions', [
            'id'     => $conversion->id,
            'status' => 'paid',
        ]);
        $this->assertNotNull(ReferralConversion::find($conversion->id)->approved_at);
        $this->assertNotNull(ReferralConversion::find($conversion->id)->paid_at);
        $this->assertEqualsWithDelta(10.00, \Juwri\Modules\Payments\Services\WalletService::balanceFor($referrer->fresh()), 0.001);

        // Idempotent: re-approving does not double-credit.
        $this->actingAs($admin)->post(route('admin.referrals.approve', $conversion->id))->assertRedirect();
        $this->assertEqualsWithDelta(10.00, \Juwri\Modules\Payments\Services\WalletService::balanceFor($referrer->fresh()), 0.001);
    }

    /** Admin can reject a conversion */
    public function test_admin_rejects_conversion(): void
    {
        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
        $referrer = $this->makeUser();
        $newbie   = $this->makeUser();

        $referral = Referral::forUser($referrer);
        $conversion = ReferralConversion::create([
            'referral_id'   => $referral->id,
            'new_user_id'   => $newbie->id,
            'status'        => 'pending',
            'reward_type'   => 'credit',
            'reward_amount' => 10.00,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.referrals.reject', $conversion->id))
            ->assertRedirect();

        $this->assertDatabaseHas('referral_conversions', ['id' => $conversion->id, 'status' => 'rejected']);
    }
}
