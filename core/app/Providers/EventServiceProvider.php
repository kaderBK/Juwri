<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Providers;

use App\Listeners\Queue\AlertOnJobFailed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobFailed;
use Juwri\Core\Events\FeatureGranted;
use Juwri\Core\Events\FeatureRevoked;
use Juwri\Core\Listeners\BroadcastEntitlementChange;
use Juwri\Modules\Auth\Listeners\RecordPrivilegedLogin;
use Juwri\Modules\Content\Listeners\ClaimStudioSession;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * The app's explicit event → listener map. Nothing is auto-discovered: the
 * framework only discovers for its own base provider class, so this list is
 * the whole wiring.
 */
class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<string>> */
    protected $listen = [
        // Sign-up: the verification mail, then W2-LISTENER's guest → owner
        // conversion that claims the visitor's AI Studio session.
        Registered::class => [SendEmailVerificationNotification::class, ClaimStudioSession::class],
        // W2-LISTENER: Login fires on EVERY request under remember-me — the
        // listener is cheap + idempotent (bails without a guest cookie session).
        // A1: the privileged sign-in audit + new-device email.
        Login::class => [ClaimStudioSession::class, RecordPrivilegedLogin::class],
        // FEATURES-5 Track A: the Apple Socialite driver (Sign in with Apple).
        SocialiteWasCalled::class => [AppleExtendSocialite::class.'@handle'],
        // F6-3: entitlement changes → `entitlement.changed` webhook topic +
        // serve-time cache bust (F6-4), and the grant analytics.
        FeatureGranted::class => [BroadcastEntitlementChange::class], // + Billing's TrackFeatureGrant (BillingServiceProvider, P1-13)
        FeatureRevoked::class => [BroadcastEntitlementChange::class], // + Billing's TrackFeatureGrant (BillingServiceProvider, P1-13)
        // P6-211: per-job-class alert on queue failure — payment/order-mail/backup
        // classes alert immediately, everything else dedupes once per hour.
        JobFailed::class => [AlertOnJobFailed::class],
    ];
}
