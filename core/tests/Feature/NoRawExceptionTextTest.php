<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\Backup\StoreImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([
        Installed::class,
        PreventRequestForgery::class,
    ]);
    app()->setLocale('en');
});

/**
 * Security regression: when a provider or database call fails during an
 * owner-facing action, the raw exception text (SQLSTATE, SQL, connection
 * details) must never reach the visitor. Only a generic, actionable alert may.
 */
function noRawExceptionDbFailure(): QueryException
{
    return new QueryException(
        'mysql',
        'select * from oauth_secrets',
        [],
        new PDOException('SQLSTATE[HY000] secret')
    );
}

function noRawExceptionOwnerStore(User $owner): Store
{
    return Store::create([
        'user_id' => $owner->id, 'title' => 'Store no-raw', 'slug' => 'no-raw-exception',
        'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
        'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
    ]);
}

/**
 * Case 1 — SocialLoginController::callback(): a QueryException from the
 * provider/database must be reported, never echoed. The visitor lands back on
 * login with a generic alert.
 */
it('hides raw provider exception text in the social callback alert', function () {
    config(['settings.google_login' => '1']);

    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andThrow(noRawExceptionDbFailure());
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $response = $this->get(route('social.callback', 'google'));

    $response->assertRedirect(route('login'));

    $message = (string) $response->getSession()->get('app_alert_message');

    expect($message)->not->toContain('SQLSTATE');
    expect($message)->not->toContain('oauth_secrets');
    expect($message)->toContain('Sign-in with this provider failed. Please try again.');
});

/**
 * Case 2 — BackupController::importUpload(): when the import service throws,
 * the flashed 'error' must not leak the raw message; the owner only needs to
 * know the file could not be imported.
 */
it('hides raw import exception text in the backup upload error', function () {
    $owner = User::factory()->create(['status' => 1]);
    $store = noRawExceptionOwnerStore($owner);

    $service = Mockery::mock(StoreImportService::class);
    $service->shouldReceive('validate')->andThrow(noRawExceptionDbFailure());
    app()->instance(StoreImportService::class, $service);

    $response = $this->actingAs($owner)
        ->from(route('backup.index'))
        ->withSession(['dashboard_store_id' => $store->id])
        ->post(route('backup.import'), [
            'archive' => UploadedFile::fake()->create('backup.zip', 10, 'application/zip'),
        ]);

    $response->assertRedirect();

    $message = (string) $response->getSession()->get('error');

    expect($message)->not->toContain('SQLSTATE');
    expect($message)->not->toContain('oauth_secrets');
    expect($message)->toContain('could not be imported');
});
