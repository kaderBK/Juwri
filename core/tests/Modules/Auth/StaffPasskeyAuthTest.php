<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Models\Passkey;
use Juwri\Modules\Auth\Models\StaffPasskey;
use Juwri\Modules\Auth\Support\PasskeySession;
use Tests\Support\Passkeys\VirtualAuthenticator;
use Tests\TestCase;

/**
 * P1-19b: staff passkeys. A staff passkey is bound to the staff row (`staff_passkeys`) and signs in as THAT staff
 * member with exactly the staff password sign-in's session (staff guard + owner-fenced web guard, staff
 * permissions) — never the owner's rights. Real attestation / assertion vectors (VirtualAuthenticator) through
 * webauthn-lib: register, sign in, owner list/revoke, inactive / deleted staff and blocked owner refused, replay,
 * expiry, wrong RP, counter regression, owner/staff passkeys never interchangeable, impersonation, hosts.
 */
class StaffPasskeyAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create([
            'status' => 1, 'email_verified_at' => now(),
            'username' => 'o'.Str::lower(Str::random(8)), 'password' => Hash::make('owner-secret'),
        ]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'pk-'.Str::lower(Str::random(6)), 'color' => '#111111',
            'title' => 'Corner Cafe', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 Test Street', 'phone' => '1234567890',
        ]);

        return [$owner->fresh(), $store];
    }

    private function staff(User $owner, Store $store, array $attrs = []): StaffMember
    {
        return StaffMember::create(array_merge([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Sara Waiter',
            'email' => 's'.Str::lower(Str::random(6)).'@example.test', 'password' => Hash::make('staff-secret'),
            'role' => 'waiter', 'active' => true,
        ], $attrs));
    }

    private function signOut(): void
    {
        Auth::guard('staff')->logout();
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->flushSession();
    }

    /** Staff signs in with the password (fresh proof), then adds a passkey. */
    private function register(StaffMember $staff, VirtualAuthenticator $key, string $name = 'My phone', string $base = 'https://localhost'): TestResponse
    {
        $this->signOut();
        $this->post($base.'/staff/login', ['email' => $staff->email, 'password' => 'staff-secret'])->assertRedirect();
        $options = $this->postJson($base.'/staff/passkeys/options')->assertOk()->json('options');

        return $this->postJson($base.'/staff/passkeys', ['name' => $name, 'credential' => $key->create($options)]);
    }

    private function login(VirtualAuthenticator $key, array $override = [], string $base = 'https://localhost'): TestResponse
    {
        $options = $this->postJson($base.'/staff/passkeys/login/options')->assertOk()->json('options');

        return $this->postJson($base.'/staff/passkeys/login', ['credential' => $key->get($options, $override)]);
    }

    private function sole(): StaffPasskey
    {
        return StaffPasskey::withoutGlobalScope(AccountScope::class)->sole();
    }

    public function test_a_staff_member_registers_a_passkey_bound_to_their_staff_row(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $key = new VirtualAuthenticator;

        $this->signOut();
        $this->post('/staff/login', ['email' => $staff->email, 'password' => 'staff-secret'])->assertRedirect();
        $this->get(route('staff.passkeys.index'))->assertOk()->assertSee('data-passkey-manage', false)->assertDontSee('data-passkey-password', false);
        $options = $this->postJson('/staff/passkeys/options')->assertOk()->json('options');
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame('Sara Waiter · Corner Cafe', $options['user']['displayName'], 'the device picker shows which staff member');
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);

        $this->postJson('/staff/passkeys', ['name' => 'My phone', 'credential' => $key->create($options)])->assertCreated();

        $pk = $this->sole();
        $this->assertSame($staff->id, (int) $pk->staff_id);
        $this->assertSame((int) $staff->store_id, (int) $pk->store_id);
        $this->assertSame((int) $staff->account_id, (int) $pk->account_id);
        $this->assertSame('localhost', $pk->rp_id);
        $this->assertSame(['internal', 'hybrid'], $pk->transports);
        $this->assertSame(0, Passkey::count(), 'never an owner passkey');
        $this->assertTrue(ActivityLog::where('action', 'auth.staff_passkey_added')->exists());
    }

    public function test_sign_in_opens_exactly_the_staff_session(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $key = new VirtualAuthenticator;
        $this->register($staff, $key)->assertCreated();
        $this->signOut();

        // Owner elevation left in this browser session must not leak into the staff session.
        $this->withSession(['auth.password_confirmed_at' => time(), 'auth.passkey_user_id' => $owner->id]);
        $this->get('/staff/login')->assertOk()->assertSee('data-passkey-login', false)->assertSee('email webauthn', false);

        $this->login($key)->assertOk()->assertJsonPath('staff.name', 'Sara Waiter')->assertJsonPath('redirect', route('dashboard'));

        $this->assertAuthenticatedAs($staff, 'staff');
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame($staff->id, (int) session('staff_session_id'));
        $this->assertNull(session('auth.password_confirmed_at'));
        $this->assertFalse(PasskeySession::active($owner));
        $this->assertSame(1, $this->sole()->sign_count);
        $this->assertTrue(ActivityLog::where('action', 'auth.staff_passkey_login')->exists());

        // Staff permissions, not the owner's: a waiter has no analytics and cannot manage the owner's passkeys.
        $this->get(route('analytics.index'))->assertForbidden();
        $this->postJson('/passkeys/options')->assertForbidden();
    }

    public function test_adding_a_passkey_later_needs_the_staff_password(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $this->register($staff, new VirtualAuthenticator)->assertCreated();

        $this->travel(4)->hours();
        $this->get(route('staff.passkeys.index'))->assertOk()->assertSee('data-passkey-password', false);
        $this->postJson('/staff/passkeys/options')->assertStatus(423);
        $this->postJson('/staff/passkeys/options', ['password' => 'owner-secret'])->assertStatus(423);
        $this->postJson('/staff/passkeys/options', ['password' => 'staff-secret'])->assertOk();
    }

    public function test_inactive_or_deleted_staff_and_staff_of_a_blocked_owner_are_refused(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $key = new VirtualAuthenticator;
        $this->register($staff, $key)->assertCreated();
        $this->signOut();

        $staff->forceFill(['active' => false])->save();
        $this->login($key)->assertForbidden();
        $this->assertGuest('staff');
        $this->assertGuest('web');

        $staff->forceFill(['active' => true])->save();
        $owner->forceFill(['status' => 0])->save();
        $this->login($key)->assertForbidden();
        $this->assertGuest('staff');

        $owner->forceFill(['status' => 1])->save();
        $staff->delete();
        $this->assertSame(0, StaffPasskey::withoutGlobalScope(AccountScope::class)->count(), 'deleted with the staff row');
        $this->login($key)->assertStatus(422);
        $this->assertGuest('staff');
    }

    public function test_the_owner_lists_and_revokes_staff_passkeys_and_nobody_else_can(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $this->register($staff, new VirtualAuthenticator, 'Till phone')->assertCreated();
        $pk = $this->sole();
        $this->signOut();

        // Another owner cannot revoke it.
        [$other] = $this->store();
        $this->actingAs($other)->delete(route('staff.passkeys.revoke', [$staff->id, $pk->id]))->assertNotFound();

        // Another staff member cannot remove it either.
        $colleague = $this->staff($owner, $store, ['name' => 'Omar Cook']);
        $this->register($colleague, new VirtualAuthenticator)->assertCreated();
        $this->delete(route('staff.passkeys.destroy', $pk->id))->assertNotFound();
        $this->signOut();

        // Not while impersonating.
        $this->actingAs($owner)->withSession(['quick_admin_user_id' => 1]);
        $this->delete(route('staff.passkeys.revoke', [$staff->id, $pk->id]))->assertForbidden();
        $this->flushSession();

        $this->actingAs($owner)->get(route('staff.index'))->assertOk()->assertSee('Till phone');
        $this->delete(route('staff.passkeys.revoke', [$staff->id, $pk->id]))->assertRedirect();
        $this->assertFalse(StaffPasskey::withoutGlobalScope(AccountScope::class)->whereKey($pk->id)->exists());
        $this->assertTrue(ActivityLog::where('action', 'staff.passkey_revoked')->exists());
    }

    public function test_replay_expiry_wrong_rp_and_counter_regression_are_refused(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $key = new VirtualAuthenticator;
        $this->register($staff, $key)->assertCreated();
        $this->signOut();

        $options = $this->postJson('/staff/passkeys/login/options')->assertOk()->json('options');
        $stored = session('passkey.challenge.staff-login');
        $assertion = $key->get($options, ['signCount' => 5]);
        $this->postJson('/staff/passkeys/login', ['credential' => $assertion])->assertOk();
        $this->signOut();
        session(['passkey.challenge.staff-login' => $stored]);
        $this->postJson('/staff/passkeys/login', ['credential' => $assertion])->assertStatus(422);
        $this->assertGuest('staff');

        $options = $this->postJson('/staff/passkeys/login/options')->assertOk()->json('options');
        $this->travel((int) config('passkeys.challenge_ttl') + 5)->seconds();
        $this->postJson('/staff/passkeys/login', ['credential' => $key->get($options)])->assertStatus(422);

        $this->login($key, ['rpId' => 'evil.test'])->assertStatus(422);
        $this->login($key, ['origin' => 'https://evil.test'])->assertStatus(422);
        $this->login($key, ['signCount' => 3])->assertStatus(422);
        $this->assertGuest('staff');
        $this->assertTrue(ActivityLog::where('action', 'auth.staff_passkey_counter_regression')->exists());

        $this->login($key, ['signCount' => 9])->assertOk();
        $this->assertAuthenticatedAs($staff, 'staff');
    }

    public function test_owner_and_staff_passkeys_are_never_interchangeable(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $staffKey = new VirtualAuthenticator;
        $this->register($staff, $staffKey)->assertCreated();

        // The owner's own passkey.
        $this->signOut();
        $ownerKey = new VirtualAuthenticator;
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $options = $this->postJson('/passkeys/options')->assertOk()->json('options');
        $this->postJson('/passkeys', ['name' => 'Owner laptop', 'credential' => $ownerKey->create($options)])->assertCreated();
        $this->signOut();

        // Staff passkey on the owner sign-in, owner passkey on the staff sign-in: both refused.
        $ownerOptions = $this->postJson('/passkeys/login/options')->assertOk()->json('options');
        $this->postJson('/passkeys/login', ['credential' => $staffKey->get($ownerOptions)])->assertStatus(422);
        $this->assertGuest('web');
        $this->login($ownerKey)->assertStatus(422);
        $this->assertGuest('staff');
        $this->assertGuest('web');

        // A credential id already used by the owner cannot be registered for the staff member.
        $clone = new VirtualAuthenticator(credentialId: $ownerKey->credentialId);
        $this->register($staff, $clone, 'Clone')->assertStatus(422);
        $this->assertSame(1, StaffPasskey::withoutGlobalScope(AccountScope::class)->count());
    }

    public function test_staff_passkeys_cannot_be_managed_while_impersonating(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $this->signOut();
        $this->post('/staff/login', ['email' => $staff->email, 'password' => 'staff-secret'])->assertRedirect();
        session(['quick_admin_user_id' => 1]);
        $this->postJson('/staff/passkeys/options')->assertForbidden();
        $this->assertSame(0, StaffPasskey::withoutGlobalScope(AccountScope::class)->count());
    }

    public function test_staff_passkeys_are_served_only_where_staff_sign_in_is(): void
    {
        $this->postJson('https://shop.example.test/staff/passkeys/login/options')->assertNotFound();

        config([
            'hosts.mode' => 'split',
            'hosts.app_url' => 'https://app.juwri.test',
            'hosts.admin_url' => 'https://admin.juwri.test',
            'hosts.storefront_url' => 'https://juwri-store.test',
            'hosts.marketing_url' => 'https://juwri.test',
        ]);
        $this->postJson('https://admin.juwri.test/staff/passkeys/login/options')->assertNotFound();
        $this->postJson('https://juwri-store.test/staff/passkeys/login/options')->assertNotFound();
        $this->postJson('https://app.juwri.test/staff/passkeys/login/options')->assertOk()->assertJsonPath('options.rpId', 'app.juwri.test');
    }

    public function test_the_staff_password_sign_in_drops_owner_elevation_too(): void
    {
        [$owner, $store] = $this->store();
        $staff = $this->staff($owner, $store);
        $this->signOut();
        $this->withSession(['auth.password_confirmed_at' => time(), 'auth.passkey_user_id' => $owner->id, 'reseller.2fa_passed' => true]);

        $this->post('/staff/login', ['email' => $staff->email, 'password' => 'staff-secret'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($staff, 'staff');
        $this->assertNull(session('auth.password_confirmed_at'));
        $this->assertNull(session('auth.passkey_user_id'));
        $this->assertNull(session('reseller.2fa_passed'));
    }
}
