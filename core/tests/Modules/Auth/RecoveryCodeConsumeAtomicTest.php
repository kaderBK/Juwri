<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use App\Models\StaffMember;
use App\Services\StaffTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Services\TwoFactorService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-268: recovery codes are consumed atomically and single-use. Two challenges
 * racing one code — or a caller holding a stale model — succeed exactly once, the
 * set is re-read under a row lock and written back with a compare-and-set, and the
 * caller's in-memory model is kept in step.
 */
class RecoveryCodeConsumeAtomicTest extends TestCase
{
    use RefreshDatabase;

    private const CODES = ['ABCDEFGH-IJKLMNOP', 'QRSTUVWX-YZ123456'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function owner(array $codes = self::CODES): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        $user = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'own'.Str::lower(Str::random(6)),
            'email' => Str::lower(Str::random(8)).'@owner.test',
            'password' => Hash::make('secret-pass-1'), 'image' => 'default_user.png',
        ]);
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $user;
    }

    private function staff(Store $store, array $codes = self::CODES): StaffMember
    {
        $staff = StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'Staff',
            'role' => 'manager', 'email' => 'staff'.Str::random(5).'@ex.com',
            'password' => Hash::make('secret'), 'active' => 1,
        ]);
        $staff->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        return $staff;
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_code_is_consumed_once_even_with_two_stale_models(): void
    {
        $user = $this->owner();
        $service = app(TwoFactorService::class);

        $a = User::find($user->id);
        $b = User::find($user->id);

        $this->assertTrue($service->useRecoveryCode($a, 'ABCDEFGH-IJKLMNOP'));
        $this->assertFalse($service->useRecoveryCode($b, 'ABCDEFGH-IJKLMNOP'));

        $this->assertSame(['QRSTUVWX-YZ123456'], array_values(User::find($user->id)->two_factor_recovery_codes));
    }

    public function test_matching_ignores_case_spaces_and_dashes(): void
    {
        $user = $this->owner();
        $service = app(TwoFactorService::class);

        $this->assertTrue($service->useRecoveryCode($user, 'abcdefgh ijklmnop'));
        $this->assertTrue($service->useRecoveryCode($user, 'qrstuvwxyz123456'));
        $this->assertFalse($service->useRecoveryCode($user, 'abcdefgh ijklmnop'));
        $this->assertSame([], array_values(User::find($user->id)->two_factor_recovery_codes));
    }

    public function test_a_wrong_or_empty_code_leaves_the_set_untouched(): void
    {
        $user = $this->owner();
        $service = app(TwoFactorService::class);

        $this->assertFalse($service->useRecoveryCode($user, 'WRONG-CODE-00000000'));
        $this->assertFalse($service->useRecoveryCode($user, '   '));
        $this->assertFalse($service->useRecoveryCode($user, ''));

        $this->assertSame(self::CODES, array_values(User::find($user->id)->two_factor_recovery_codes));
    }

    public function test_the_caller_model_is_refreshed_in_memory_without_dirty_state(): void
    {
        $user = $this->owner();
        $service = app(TwoFactorService::class);

        $this->assertTrue($service->useRecoveryCode($user, 'ABCDEFGH-IJKLMNOP'));

        $this->assertSame(['QRSTUVWX-YZ123456'], array_values($user->two_factor_recovery_codes));
        $this->assertFalse($user->isDirty());
    }

    public function test_staff_recovery_codes_are_consumed_once_across_stale_models(): void
    {
        $store = $this->store($this->owner());
        $staff = $this->staff($store);

        $a = StaffMember::find($staff->id);
        $b = StaffMember::find($staff->id);

        $this->assertTrue(StaffTwoFactorService::verify($a, 'ABCDEFGH-IJKLMNOP'));
        $this->assertFalse(StaffTwoFactorService::verify($b, 'ABCDEFGH-IJKLMNOP'));

        $this->assertSame(['QRSTUVWX-YZ123456'], array_values(StaffMember::find($staff->id)->two_factor_recovery_codes));
    }
}
