<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Http\Middleware\AcceptLegacyStoreKeys;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Core\Http\Middleware\AddPaymentPageCsp;
use Juwri\Core\Http\Middleware\AddRequestContext;
use Juwri\Core\Http\Middleware\AddSecurityHeaders;
use Juwri\Core\Http\Middleware\AdminImpersonationGuard;
use Juwri\Core\Http\Middleware\AdminSessionGuard;
use Juwri\Core\Http\Middleware\ApplyHostProfile;
use Juwri\Core\Http\Middleware\BlockBadBots;
use Juwri\Core\Http\Middleware\BlockInAppPurchases;
use Juwri\Core\Http\Middleware\BranchRateLimit;
use Juwri\Core\Http\Middleware\EditionSlot;
use Juwri\Core\Http\Middleware\EnforceHostSplit;
use Juwri\Core\Http\Middleware\EnsureEntitled;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Http\Middleware\EnsureLocalePrefix;
use Juwri\Core\Http\Middleware\EnsureTenantOwnsResource;
use Juwri\Core\Http\Middleware\IsDemoMode;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\MeterRequests;
use Juwri\Core\Http\Middleware\PreventSessionBlockingOnPolls;
use Juwri\Core\Http\Middleware\RememberLocale;
use Juwri\Core\Http\Middleware\ServePublicSvg;
use Juwri\Core\Http\Middleware\SetTenantContext;
use Juwri\Core\Http\Middleware\StartSession; // P6-146: no stale session cookie after a concurrent login
use Juwri\Core\Http\Middleware\StoreDailyCap;
use Juwri\Core\Http\Middleware\SubstituteBindingsSafe;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use Juwri\Core\Http\Middleware\TurboFormResponses;
use Juwri\Core\Http\Middleware\UpdateLastActive;
use Juwri\Modules\AppStore\Http\Middleware\VerifyAppSessionToken;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Auth\Http\Middleware\CheckRegistrationDisabled;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Juwri\Modules\Auth\Http\Middleware\RedirectIfAuthenticated;
use Juwri\Modules\Auth\Http\Middleware\RequirePassword;
use Juwri\Modules\Content\Http\Middleware\CacheMarketingResponse;
use Juwri\Modules\Marketing\Http\Middleware\CaptureGuestReferral;
use Juwri\Modules\Pos\Http\Middleware\KioskLock;
use Juwri\Modules\Storefront\Http\Middleware\CacheStorefrontResponse;
use Juwri\Modules\Storefront\Http\Middleware\RedirectToPrimaryStoreAddress;
use Juwri\Modules\Storefront\Http\Middleware\ResolveStoreHost;
use Juwri\Modules\Tables\Http\Middleware\EnsureTableSessionVerified;
use Juwri\Modules\Team\Http\Middleware\StaffSessionFence;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\CheckTokenForAnyScope;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        BlockBadBots::class, // P6-147: .htaccess UA blocklist, app-level (OpenLiteSpeed ignores SetEnvIf/Require)
        TrustProxies::class,
        TrustHosts::class,
        ServePublicSvg::class, // P6-147: /storage/*.svg with the sandbox CSP on every server (rewritten here by .htaccess)
        ResolveStoreHost::class, // P1-20: store host (custom domain / subdomain) → store; maps its paths onto the storefront routes
        ApplyHostProfile::class, // P1-20: split mode — per-origin session cookie, admin host lifetime/2FA/IP allowlist
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        AcceptLegacyStoreKeys::class, // P1-04: legacy store request keys → store_id; api/* JSON mirrors the old key
        AddSecurityHeaders::class, // ISS-V-C-002: global X-Content-Type-Options: nosniff
        AddPaymentPageCsp::class, // PCI DSS 4.0 6.4.3: strict CSP on payment pages (config/payment_scripts.php)
        PreventSessionBlockingOnPolls::class, // runs before the web StartSession: no session write-lock on read-only pollers (else concurrent same-session polls 500 with LockTimeoutException)
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            EnforceHostSplit::class, // P1-20: split mode — what each origin may serve; session bound to its origin (before AuthenticateSession: middleware priority moves `auth` there)
            AuthenticateSession::class,
            AddRequestContext::class, // O12b: request-id + tenant log context
            SetTenantContext::class, // P1-04 slice 5: tenant scope on owner-facing routes
            ShareErrorsFromSession::class,
            TurboFormResponses::class, // Owner app S7: Turbo form posts get a 303 / 422 (never a 200 page or JSON)
            PreventRequestForgery::class,
            KioskLock::class, // P3-06: a kiosk browser session reaches only its own store's storefront (staff exit PIN ends it)
            SubstituteBindingsSafe::class,
            UpdateLastActive::class,
            EditionSlot::class.':web-branding', // #86: white-label branding (Reseller, ee — P1-13 slot)
            RedirectToPrimaryStoreAddress::class, // P1-20: non-primary store addresses 301 to the primary one
            EditionSlot::class.':web-guards', // reseller-domain site guard + reseller→customer impersonation guard (ee — P1-13 slot)
            MeterRequests::class, // Abuse Guard P7: per-tenant request metering (terminate-time, cache-only; Billing's RequestMeter, P1-13)
            CaptureGuestReferral::class, // HPMS E4: stash `?ref=CODE` for guest refer-a-friend
            BlockInAppPurchases::class, // Owner app S4 (Apple 3.1.3): no purchases inside the native app
            StaffSessionFence::class, // staff sessions only reach auth routes the staff map allows
            AdminImpersonationGuard::class, // admin "log in as user": 60-min cap + sensitive pages blocked
        ],

        'api' => [
            ThrottleRequests::class.':api',
            SubstituteBindingsSafe::class,
            AddRequestContext::class, // O12b: request-id + tenant log context
            MeterRequests::class, // Abuse Guard P7: per-tenant request metering (Billing's RequestMeter, P1-13)
            SetTenantContext::class, // P1-04 slice 5: tenant scope on owner-facing API routes
        ],
    ];

    /**
     * Short names used for middleware in the route files.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'cache.headers' => SetCacheHeaders::class,
        'cache.marketing' => CacheMarketingResponse::class,
        'admin.session' => AdminSessionGuard::class,
        'cache.storefront' => CacheStorefrontResponse::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        /* Abuse Guard: warn/enforce-aware named rate limiter (->middleware('ratelimit:ai')) */
        'ratelimit' => AbuseRateLimit::class,
        /* P6-74: per-store daily cost cap on public AI/SMS endpoints (->middleware('storecap:ai'|'storecap:sms')) */
        'storecap' => StoreDailyCap::class,
        /* Abuse Guard: per-merchant abuse-hold write freeze (public menus stay live) */
        'abuse.hold' => AbuseHoldWall::class,
        /* Abuse Guard: per-restaurant/branch ceilings on public writes (->middleware('branchlimit:orders')) */
        'branchlimit' => BranchRateLimit::class,
        /* GAPS-RUN-2 B0: superadmin feature kill-switch gate (->middleware('feature:pos_bridge')) */
        'feature' => EnsureFeatureEnabled::class,
        /* P1-4 feature-platform: plan-aware entitlement gate (->middleware('entitled:hotel')) */
        'entitled' => EnsureEntitled::class,
        /* P1-13: ee aliases are registered by their module providers — reseller.can, reseller.suspension (Reseller;
           Core defaults reseller.suspension to a pass-through), mk.context, mk.gate, dir.gate (Marketplace). */
        /* PARITY-RUN T3: dine-in scan gate (->middleware('table.session')). NOT a
           feature gate — the route it guards is anonymous; see the class docblock. */
        'table.session' => EnsureTableSessionVerified::class,

        /* P3-1: Passport OAuth scope gates for third-party app tokens */
        'scopes' => CheckToken::class,
        'scope' => CheckTokenForAnyScope::class,
        /* P3-3: embedded-app session-token (JWT) authentication */
        'auth.app-session' => VerifyAppSessionToken::class,

        /* locale selection (mcamara + our Locale / RememberLocale) */
        'localize' => LaravelLocalizationRoutes::class,
        'app.locale' => Locale::class,
        'localizationRedirect' => LaravelLocalizationRedirectFilter::class,
        'localeSessionRedirect' => LocaleSessionRedirect::class,
        'localeCookieRedirect' => LocaleCookieRedirect::class,
        // rev/seo R14: remember-only replacement for localeSessionRedirect
        'locale.remember' => RememberLocale::class,
        'locale.prefix' => EnsureLocalePrefix::class,
        'localeViewPath' => LaravelLocalizationViewPath::class,

        'installed' => Installed::class,
        'admin' => AdminMiddleware::class,
        'checkUserIsBanned' => CheckUserIsBanned::class,
        'checkRegistrationDisabled' => CheckRegistrationDisabled::class,
        'demo' => IsDemoMode::class,
        'staff.dashboard' => EnsureStaffDashboardPermission::class,
        'dashboard.sync' => SyncDashboardStore::class,
        'tenant.owns' => EnsureTenantOwnsResource::class, // S13 cross-tenant IDOR backstop
        'owner.screen' => TrackOwnerScreenView::class, // PWA-SPEC §3.6 mobile usage stats
        'csp.dashboard' => AddDashboardCspHeaders::class, // S12 CSP on privileged surfaces
        // the same policy on marketing / auth / studio pages under another name: `csp.dashboard` also MARKS a route as a
        // dashboard route for EnforceHostSplit (a storefront-host route in the marketing group then 404'd in split mode)
        'csp.marketing' => AddDashboardCspHeaders::class,
        // P6-122: the same enforced policy on the driver apps, POS register, Theme Store and App Store. Not a dashboard
        // marker either (those routes keep their own host rules), and the name says they are app surfaces.
        'csp.app' => AddDashboardCspHeaders::class,
    ];
}
