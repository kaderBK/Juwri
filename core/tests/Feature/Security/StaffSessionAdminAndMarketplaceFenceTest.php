<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEC-W2 (Hydrogen review #1, #6): a staff dashboard session signs the web guard in
 * AS THE OWNER (StaffLoginController). Any route that only checks `auth()->check()`
 * or `admin` therefore lets every staff role act as the owner:
 *
 *  - #1 marketplace.buy / marketplace.apply: spend the owner's wallet on a theme and
 *    switch the owner's storefront theme.
 *  - #6 module superadmin pages (AI provider keys, social-login providers): when the
 *    owner is a superadmin, a staff session passed `admin` and reached platform secrets;
 *    the module groups also skipped the `admin.session` idle timeout.
 *
 * `staff.dashboard` default-denies unmapped routes for staff sessions (owner passes).
 */

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->setLocale('en');
    $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
});

function secW2OwnerWithStaff(bool $superadmin = false): array
{
    $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()] + ($superadmin ? ['user_type' => 'admin'] : []));
    $title = 'R'.Str::random(6);
    $store = Store::create([
        'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
        'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
        'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
    ]);
    $staff = StaffMember::create([
        'owner_id' => $owner->id, 'store_id' => $store->id,
        'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
        'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
    ]);

    return [$owner, $store, $staff];
}

it('fences every admin.* route with staff.dashboard and admin.session', function () {
    $missing = [];
    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'admin.')) {
            continue;
        }
        $mw = $route->gatherMiddleware();
        if (! in_array('admin', $mw, true)) {
            continue;
        }
        foreach (['staff.dashboard', 'admin.session'] as $needed) {
            if (! in_array($needed, $mw, true)) {
                $missing[] = $name.' lacks '.$needed;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('refuses a staff session of a superadmin owner on the module admin pages', function (string $routeName) {
    [$owner, , $staff] = secW2OwnerWithStaff(superadmin: true);

    // Staff guard first, web (= owner) last: actingAs() makes its guard the default,
    // and a real staff login leaves the default web guard signed in as the owner.
    $this->actingAs($staff, 'staff')->actingAs($owner, 'web')
        ->withSession(['staff_session_id' => $staff->id])
        ->get(route($routeName))
        ->assertForbidden();
})->with(['admin.ai-providers.index', 'admin.social-providers.index']);

it('refuses a staff session of a superadmin owner on the verified-badge toggle', function () {
    [$owner, $store, $staff] = secW2OwnerWithStaff(superadmin: true);

    $this->actingAs($staff, 'staff')->actingAs($owner, 'web')
        ->withSession(['staff_session_id' => $staff->id])
        ->post(route('admin.stores.verified.toggle', ['id' => $store->id]))
        ->assertForbidden();

    expect((bool) $store->fresh()->is_verified)->toBeFalse();
});

it('refuses a staff session on marketplace buy and apply', function (string $routeName) {
    [$owner, $store, $staff] = secW2OwnerWithStaff();

    // Staff guard first, web (= owner) last: actingAs() makes its guard the default,
    // and a real staff login leaves the default web guard signed in as the owner.
    $this->actingAs($staff, 'staff')->actingAs($owner, 'web')
        ->withSession(['staff_session_id' => $staff->id])
        ->postJson(route($routeName, ['slug' => 'no-such-theme']), ['store_id' => $store->id])
        ->assertForbidden();
})->with(['marketplace.buy', 'marketplace.apply']);

it('still lets the owner reach marketplace buy and apply', function (string $routeName) {
    [$owner, $store] = secW2OwnerWithStaff();

    // Unknown theme → the controller's 404, i.e. past the middleware fence.
    $this->actingAs($owner)
        ->postJson(route($routeName, ['slug' => 'no-such-theme']), ['store_id' => $store->id])
        ->assertNotFound();
})->with(['marketplace.buy', 'marketplace.apply']);

// Opus review SHOULD 7: the controller refuses a staff session itself, so moving the routes can never drop the fence.
it('refuses a staff session in marketplace buy and apply even without the route fence', function (string $routeName) {
    [$owner, $store, $staff] = secW2OwnerWithStaff();
    $this->withoutMiddleware(EnsureStaffDashboardPermission::class);

    $this->actingAs($staff, 'staff')->actingAs($owner, 'web')
        ->withSession(['staff_session_id' => $staff->id])
        ->postJson(route($routeName, ['slug' => 'no-such-theme']), ['store_id' => $store->id])
        ->assertForbidden();
})->with(['marketplace.buy', 'marketplace.apply']);

// Opus review SHOULD 6: `admin` itself refuses a staff session, so an admin group without staff.dashboard is covered.
it('refuses a staff session of a superadmin owner in the admin middleware itself', function () {
    config(['auth.force_2fa_admin' => false]);
    [$owner, , $staff] = secW2OwnerWithStaff(superadmin: true);
    $this->actingAs($staff, 'staff')->actingAs($owner, 'web');
    session(['staff_session_id' => $staff->id]);

    expect(fn () => (new AdminMiddleware)->handle(Request::create('/admin/probe'), fn () => response('admin-ok')))
        ->toThrow(HttpException::class);
});

it('still lets the superadmin owner through the admin middleware', function () {
    config(['auth.force_2fa_admin' => false]);
    [$owner] = secW2OwnerWithStaff(superadmin: true);
    $this->actingAs($owner);

    $response = (new AdminMiddleware)->handle(Request::create('/admin/probe'), fn () => response('admin-ok'));

    expect($response->getContent())->toBe('admin-ok');
});

it('keeps guests out of marketplace buy and apply', function (string $routeName) {
    $this->postJson(route($routeName, ['slug' => 'no-such-theme']))
        ->assertStatus(401);
})->with(['marketplace.buy', 'marketplace.apply']);
