<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\StaffMember;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Team\Models\StaffToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-269: a native push subscription remembers the app sign-in it was registered
 * under, so logout, credential changes, session revokes and driver-token rotation
 * delete the device's push token with its bearer token. A sold or stolen phone
 * stops receiving order pushes.
 */
class PushDevicesRevokedWithSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        Mail::fake();

        config(['settings.admin_allow_mobile_app_push' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)),
            'email' => Str::lower(Str::random(8)).'@owner.test',
            'password' => Hash::make(self::PASSWORD), 'image' => 'default_user.png',
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $store, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S'.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => Hash::make('secret'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function driver(User $owner, Store $store, string $name = 'Driver'): Driver
    {
        return Driver::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => $name,
            'token' => hash('sha256', Str::random(40)), 'active' => true,
        ]);
    }

    /** @return array<string, string> */
    private function bearer(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw];
    }

    private function appToken(User $user, string $device = 'device-1'): string
    {
        return (string) $this->postJson('/api/v1/app/auth/login', [
            'username' => $user->email, 'password' => self::PASSWORD,
            'device_id' => $device, 'platform' => 'ios', 'device_name' => 'Test iPhone',
        ])->assertOk()->json('token');
    }

    private function makeAppToken(User $user, string $device = 'device-1'): ApiToken
    {
        return ApiToken::create([
            'user_id' => $user->id, 'name' => 'Mobile app', 'token' => hash('sha256', Str::random(64)),
            'abilities' => ['read'], 'kind' => 'app', 'device_id' => $device, 'platform' => 'ios',
            'expires_at' => now()->addDays(180),
        ]);
    }

    private function registerOwnerDevice(string $raw, Store $store, string $token, string $platform = 'ios'): PushSubscription
    {
        $this->postJson('/api/v1/app/devices', [
            'store_id' => $store->id, 'token' => $token, 'platform' => $platform,
            'device_name' => 'Owner iPhone', 'app_version' => '1.0.0',
        ], $this->bearer($raw))->assertOk();

        return PushSubscription::where('endpoint', $token)->firstOrFail();
    }

    private function registerStaffDevice(string $raw, string $token, string $platform = 'fcm'): PushSubscription
    {
        $this->postJson('/api/v1/staff/push/register-device', [
            'token' => $token, 'platform' => $platform,
        ], $this->bearer($raw))->assertOk();

        return PushSubscription::where('endpoint', $token)->firstOrFail();
    }

    private function changePasswordViaSettings(User $user, ?int $keepTokenId = null): void
    {
        $this->actingAs($user)->withSession($keepTokenId ? ['owner_app_token_id' => $keepTokenId] : [])
            ->post(route('settings.editProfile'), [
                'username' => $user->username,
                'email' => $user->email,
                'current_password' => self::PASSWORD,
                'new_password' => 'another-pass-3',
                'new_password_confirmation' => 'another-pass-3',
                'keep_this_device' => $keepTokenId ? '1' : null,
            ])->assertRedirect();
    }

    // ── owner app ─────────────────────────────────────────────────────────────

    public function test_owner_logout_without_a_push_token_revokes_the_registered_device(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $raw = $this->appToken($owner);
        $tokenId = (int) ApiToken::where('user_id', $owner->id)->firstOrFail()->id;

        $sub = $this->registerOwnerDevice($raw, $store, 'push-owner-1');
        $this->assertSame($tokenId, (int) $sub->api_token_id);
        $this->assertSame((int) $store->id, (int) $sub->store_id);

        // No push_token in the body: the row still goes with its bearer token.
        $this->postJson('/api/v1/app/auth/logout', [], $this->bearer($raw))->assertOk();

        $this->assertSame(0, PushSubscription::where('endpoint', 'push-owner-1')->count());
        $this->assertSame(0, ApiToken::where('user_id', $owner->id)->count());
    }

    public function test_settings_password_change_revokes_native_owner_devices_but_not_web_push(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $first = $this->makeAppToken($owner, 'device-1');
        $second = $this->makeAppToken($owner, 'device-2');

        PushSubscription::storeNative('native-1', 'fcm', (int) $store->id, null, (int) $owner->id, ['api_token_id' => (int) $first->id]);
        PushSubscription::storeNative('native-2', 'fcm', (int) $store->id, null, (int) $owner->id, ['api_token_id' => (int) $second->id]);
        PushSubscription::storeWeb(
            ['endpoint' => 'https://web/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']],
            (int) $store->id,
            (int) $owner->id
        );

        $this->changePasswordViaSettings($owner);

        $this->assertSame(0, PushSubscription::whereIn('endpoint', ['native-1', 'native-2'])->count());
        $this->assertSame(1, PushSubscription::where('endpoint', 'https://web/x')->count());
        $this->assertNull($first->fresh());
        $this->assertNull($second->fresh());
    }

    public function test_keep_this_device_spares_only_the_current_app_tokens_push_row(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $kept = $this->makeAppToken($owner, 'device-1');
        $dropped = $this->makeAppToken($owner, 'device-2');

        PushSubscription::storeNative('native-1', 'fcm', (int) $store->id, null, (int) $owner->id, ['api_token_id' => (int) $kept->id]);
        PushSubscription::storeNative('native-2', 'fcm', (int) $store->id, null, (int) $owner->id, ['api_token_id' => (int) $dropped->id]);
        PushSubscription::storeWeb(
            ['endpoint' => 'https://web/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']],
            (int) $store->id,
            (int) $owner->id
        );

        $this->changePasswordViaSettings($owner, (int) $kept->id);

        $this->assertNotNull($kept->fresh());
        $this->assertNull($dropped->fresh());
        $this->assertSame(1, PushSubscription::where('endpoint', 'native-1')->count());
        $this->assertSame(0, PushSubscription::where('endpoint', 'native-2')->count());
        $this->assertSame(1, PushSubscription::where('endpoint', 'https://web/x')->count());
    }

    public function test_deleting_an_app_token_from_the_api_page_revokes_its_device(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $token = $this->makeAppToken($owner, 'device-1');

        PushSubscription::storeNative('native-1', 'fcm', (int) $store->id, null, (int) $owner->id, ['api_token_id' => (int) $token->id]);

        $this->actingAs($owner)->delete(route('api.token.delete', $token->id))->assertRedirect();

        $this->assertNull($token->fresh());
        $this->assertSame(0, PushSubscription::where('endpoint', 'native-1')->count());
    }

    public function test_re_login_on_the_same_device_revokes_the_replaced_tokens_device(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $raw = $this->appToken($owner, 'device-1');
        $this->registerOwnerDevice($raw, $store, 'native-1');

        $this->appToken($owner, 'device-1');

        $this->assertSame(0, PushSubscription::where('endpoint', 'native-1')->count());
        $this->assertSame(1, ApiToken::where('user_id', $owner->id)->where('kind', 'app')->count());
    }

    // ── staff app ─────────────────────────────────────────────────────────────

    public function test_staff_logout_revokes_only_its_own_device(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $staff = $this->staff($store);
        $other = $this->staff($store, 'cashier');

        $raw = StaffToken::issue($staff, 'tablet');
        $otherRaw = StaffToken::issue($other, 'tablet');

        $sub = $this->registerStaffDevice($raw, 'staff-push-1');
        $otherSub = $this->registerStaffDevice($otherRaw, 'staff-push-2');

        $tokenId = (int) StaffToken::where('staff_id', $staff->id)->firstOrFail()->id;
        $this->assertSame($tokenId, (int) $sub->staff_token_id);
        $this->assertSame((int) $staff->id, (int) $sub->staff_id);
        $this->assertSame((int) $other->id, (int) $otherSub->staff_id);

        $this->postJson('/api/v1/staff/logout', [], $this->bearer($raw))->assertOk();

        $this->assertSame(0, PushSubscription::where('endpoint', 'staff-push-1')->count());
        $this->assertSame(1, PushSubscription::where('endpoint', 'staff-push-2')->count());
        $this->assertSame(0, StaffToken::where('staff_id', $staff->id)->count());
        $this->assertSame(1, StaffToken::where('staff_id', $other->id)->count());
    }

    public function test_staff_password_change_revokes_every_device_of_that_staff(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $staff = $this->staff($store);
        $other = $this->staff($store);

        PushSubscription::storeNative('staff-dev-1', 'fcm', (int) $store->id, (int) $staff->id);
        PushSubscription::storeNative('staff-dev-2', 'apns', (int) $store->id, (int) $staff->id);
        PushSubscription::storeNative('other-dev', 'fcm', (int) $store->id, (int) $other->id);

        $staff->update(['password' => Hash::make('new-staff-pass')]);

        $this->assertSame(0, PushSubscription::where('staff_id', $staff->id)->count());
        $this->assertSame(1, PushSubscription::where('staff_id', $other->id)->count());
    }

    public function test_staff_deactivation_revokes_every_device_of_that_staff(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $staff = $this->staff($store);

        PushSubscription::storeNative('staff-dev-1', 'fcm', (int) $store->id, (int) $staff->id);

        $staff->update(['active' => false]);

        $this->assertSame(0, PushSubscription::where('staff_id', $staff->id)->count());
    }

    public function test_staff_deletion_revokes_devices_and_app_tokens(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $staff = $this->staff($store);
        $other = $this->staff($store);

        StaffToken::issue($staff, 'tablet');
        StaffToken::issue($other, 'tablet');
        PushSubscription::storeNative('staff-dev-1', 'fcm', (int) $store->id, (int) $staff->id);
        PushSubscription::storeNative('other-dev', 'fcm', (int) $store->id, (int) $other->id);

        $staff->delete();

        $this->assertSame(0, PushSubscription::where('staff_id', $staff->id)->count());
        $this->assertSame(0, StaffToken::where('staff_id', $staff->id)->count());
        $this->assertSame(1, PushSubscription::where('staff_id', $other->id)->count());
        $this->assertSame(1, StaffToken::where('staff_id', $other->id)->count());
    }

    // ── driver ────────────────────────────────────────────────────────────────

    public function test_driver_token_rotation_revokes_their_devices(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $driver = $this->driver($owner, $store, 'Alice');
        $other = $this->driver($owner, $store, 'Bob');

        PushSubscription::storeNative('driver-dev-1', 'fcm', (int) $store->id, null, null, ['driver_id' => (int) $driver->id]);
        PushSubscription::storeNative('driver-dev-other', 'fcm', (int) $store->id, null, null, ['driver_id' => (int) $other->id]);

        $driver->update(['token' => hash('sha256', 'new')]);

        $this->assertSame(0, PushSubscription::where('endpoint', 'driver-dev-1')->count());
        $this->assertSame(1, PushSubscription::where('endpoint', 'driver-dev-other')->count());
    }

    public function test_driver_deactivation_revokes_their_devices(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $driver = $this->driver($owner, $store, 'Alice');

        PushSubscription::storeNative('driver-dev-1', 'fcm', (int) $store->id, null, null, ['driver_id' => (int) $driver->id]);

        $driver->update(['active' => false]);

        $this->assertSame(0, PushSubscription::where('endpoint', 'driver-dev-1')->count());
    }
}
