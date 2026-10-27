<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Customers\Console\Commands\FeedbackDispatchRequests;
use Juwri\Modules\Customers\Mail\CustomerClaimVerify;
use Juwri\Modules\Customers\Mail\CustomerContactChange;
use Juwri\Modules\Customers\Support\CustomerSignIn;

/**
 * Customers module provider (P1-08c).
 */
class CustomersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Customers', new CustomersStoreData);
        ModuleRoutes::register('Customers', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [CustomerClaimVerify::class, CustomerContactChange::class]);
    }

    public function boot(): void
    {
        // P1-19 (customer passkeys): every storefront passkey endpoint, per IP. Failed sign-ins also count toward
        // CustomerPasskeyController's own per-IP lockout.
        RateLimiter::for('customer-passkey', function (Request $request) {
            return Limit::perMinute(20)->by('customer-passkey|'.$request->ip());
        });

        // P1-19 review fix: remember when the customer last signed in interactively (fresh proof for adding a
        // passkey without a password).
        Event::listen(Login::class, [CustomerSignIn::class, 'onLogin']);

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([FeedbackDispatchRequests::class]);
        }
    }
}
