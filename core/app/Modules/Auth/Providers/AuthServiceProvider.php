<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Auth\Contracts\EmailChangeUndos;
use Juwri\Modules\Auth\Contracts\PasskeyCeremonies;
use Juwri\Modules\Auth\Contracts\SocialCredentials;
use Juwri\Modules\Auth\Contracts\SsoProvider;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Juwri\Modules\Auth\Listeners\PurgePasswordResetTokens;
use Juwri\Modules\Auth\Mail\NewDeviceLogin;
use Juwri\Modules\Auth\Services\EmailChangeUndoService;
use Juwri\Modules\Auth\Services\Social\SocialCredentialResolver;
use Juwri\Modules\Auth\Services\Sso\NoSsoProvider;
use Juwri\Modules\Auth\Support\PasskeyCeremonyService;

/**
 * Auth module provider (P1-08 L1 public surface): binds the module's public contracts. (App\Providers\AuthServiceProvider
 * is the app's gate/policy provider — a different class.)
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Auth', new AuthStoreData);
        ModuleRoutes::register('Auth', __DIR__.'/../Routes');
        // P6-192: the new-device sign-in mail is account-level (privileged users), like ResetPassword.
        TenantJobs::classify(platform: [NewDeviceLogin::class]);
        // scoped: one resolver (and its row memo) per request; Octane resets it between requests
        $this->app->scoped(SocialCredentialResolver::class);
        $this->app->bind(SocialCredentials::class, fn ($app) => $app->make(SocialCredentialResolver::class));
        $this->app->singleton(PasskeyCeremonies::class, PasskeyCeremonyService::class);
        // P1-13 13h: SSO/SAML/SCIM is ee — Community default none (an ee provider binds its own).
        $this->app->singletonIf(SsoProvider::class, NoSsoProvider::class);
        // BL-036 A1a: "This wasn't me — undo" links on email-change notices.
        $this->app->bind(EmailChangeUndos::class, EmailChangeUndoService::class);
    }

    public function boot(): void
    {
        // P6-267: outstanding password-reset links die with any credential change.
        Event::listen(
            CredentialsChanged::class,
            PurgePasswordResetTokens::class
        );
    }
}
