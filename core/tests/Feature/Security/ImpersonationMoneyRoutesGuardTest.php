<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ImpersonationRoutes;
use Juwri\Modules\Payments\Services\WalletService;

uses(RefreshDatabase::class);

/*
 * sec-w1 (security review #2, #7, #10): wallet payout/redeem, add-on trial/cancel, subscription switch/renew and
 * partner sandbox/themes/review-reply must be off-limits to a reseller or admin impersonation session.
 * Read-only pages keep rendering.
 */

dataset('guarded money routes', [
    'wallet.withdraw',
    'wallet.redeem',
    'wallet.stripe.connect',
    'wallet.stripe.return',
    'addons.trial',
    'addons.cancel',
    'subscription.scheduleSwitch',
    'subscription.cancelSwitch',
    'subscription.renewNow',
    'partner.sandbox',
    'partner.reviews.reply',
    'partner.themes.store',
    'partner.themes.update',
    'partner.themes.media',
    'partner.themes.media.delete',
    // Opus review MUST 1: theme/app purchases debit the wallet or start a gateway charge / top-up.
    'marketplace.buy',
    'apps.install',
    'user.store.buy.theme',
    'user.store.install.app',
    'user.store.buy.app.card',
    'user.store.topup.continue',
]);

dataset('read-only pages', [
    'wallet.index',
    'wallet.topup',
    'addons.index',
    'subscription',
    'partner.overview',
    'partner.earnings',
    'user.store.apps',
    'user.store.apps.show',
    'user.store.themes',
    'user.store.themes.show',
    'marketplace.apply', // a theme switch, not money: decided on purpose (Opus review MUST 1)
]);

// Community has no edition packages, so edition routes (wallet.*, addons.*, ...) are absent there: only the
// full edition must have them registered.
it('blocks the route while impersonating', function (string $name) {
    if (Route::has($name) || EditionPackages::all() !== []) {
        expect(Route::has($name))->toBeTrue("route {$name} is not registered");
    }

    expect(ImpersonationRoutes::blocks($name))->toBeTrue();
})->with('guarded money routes');

it('keeps read-only pages reachable while impersonating', function (string $name) {
    if (Route::has($name) || EditionPackages::all() !== []) {
        expect(Route::has($name))->toBeTrue("route {$name} is not registered");
    }

    expect(ImpersonationRoutes::blocks($name))->toBeFalse();
})->with('read-only pages');

it('refuses a wallet withdrawal inside an admin log-in-as session', function () {
    if (! Route::has('wallet.withdraw')) {
        $this->markTestSkipped('wallet.withdraw is an edition route; not registered in Community.');
    }

    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->withSession(['quick_admin_user_id' => 999999, 'quick_admin_impersonation' => ['started_at' => now()->timestamp]])
        ->post(route('wallet.withdraw'), ['amount' => '10'])
        ->assertForbidden();
});

/** A published 15.00 theme and its would-be buyer with 20.00 in the platform wallet. */
function impersonationBuyFixture(): User
{
    Theme::create(['name' => 'Imp Theme', 'slug' => 'imp-theme', 'status' => 'published', 'price' => 15]);
    $customer = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    WalletService::credit($customer, 20, 'seed');

    return $customer;
}

it('refuses a theme purchase inside an admin log-in-as session and leaves the wallet alone', function () {
    $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    $customer = impersonationBuyFixture();

    $this->actingAs($customer)
        ->withSession(['quick_admin_user_id' => 999999, 'quick_admin_impersonation' => ['started_at' => now()->timestamp]])
        ->postJson(route('marketplace.buy', ['slug' => 'imp-theme']))
        ->assertForbidden();

    expect(WalletService::balanceFor($customer->fresh()))->toEqualWithDelta(20.0, 0.001);
});

it('still lets the owner buy a theme outside impersonation', function () {
    $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    $customer = impersonationBuyFixture();

    $this->actingAs($customer)->postJson(route('marketplace.buy', ['slug' => 'imp-theme']))->assertOk();

    expect(WalletService::balanceFor($customer->fresh()))->toEqualWithDelta(5.0, 0.001);
});
