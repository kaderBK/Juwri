<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Juwri\Core\Auth\CaseInsensitivePresenceVerifier;
use Juwri\Core\Auth\CaseInsensitiveUserProvider;
use Juwri\Modules\Auth\Services\DualSecretClientRepository;
use Juwri\Modules\Content\Models\Adsense;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use App\Models\Coupon;
use Juwri\Modules\Content\Models\Faq;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Notification;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Core\Models\Store;
use Juwri\Modules\Marketing\Models\Subscriber;
use Juwri\Modules\Content\Models\Testimonial;
use Juwri\Modules\Content\Policies\Admin\AdsensePolicy;
use Juwri\Modules\Menu\Policies\Admin\AllergyPolicy;
use Juwri\Modules\Loyalty\Policies\Admin\CouponPolicy;
use Juwri\Modules\Payments\Policies\Admin\PaymentGatewayPolicy;
use Juwri\Modules\Content\Policies\Admin\BlogCategoryPolicy;
use Juwri\Modules\Content\Policies\Admin\BlogCommentPolicy;
use Juwri\Modules\Content\Policies\Admin\BlogPolicy;
use Juwri\Modules\Content\Policies\Admin\FaqPolicy;
use App\Policies\Admin\LanguagePolicy;
use Juwri\Core\Policies\Admin\NotificationPolicy;
use Juwri\Modules\Content\Policies\Admin\PagePolicy;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Modules\Marketing\Policies\Admin\SubscriberPolicy;
use Juwri\Modules\Content\Policies\Admin\TestimonialPolicy;
use Juwri\Core\Policies\StorePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Store::class => StorePolicy::class,

        // WI-08: records managed from the superadmin panel.
        Adsense::class => AdsensePolicy::class,
        Allergy::class => AllergyPolicy::class,
        Blog::class => BlogPolicy::class,
        BlogCategory::class => BlogCategoryPolicy::class,
        BlogComment::class => BlogCommentPolicy::class,
        Faq::class => FaqPolicy::class,
        Language::class => LanguagePolicy::class,
        Notification::class => NotificationPolicy::class,
        Page::class => PagePolicy::class,
        Subscriber::class => SubscriberPolicy::class,
        Testimonial::class => TestimonialPolicy::class,

        // WI-08, platform billing (moves to the Enterprise package with the Billing area, J22).
        Coupon::class => CouponPolicy::class,
        PaymentGateway::class => PaymentGatewayPolicy::class,
        // Plan, PlanOption, Tax, Transaction admin policies: BillingServiceProvider (P1-13).
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // WI-08: superadmin panel areas without a model (plugins, mail templates, site template).
        PanelAbilities::register();

        // P6-40 (PG-SQL-AUDIT O1): email/username sign-in, reset links and unique:/exists: rules match
        // case-insensitively on every driver (MySQL's ci collation did; PostgreSQL does not).
        Auth::provider('eloquent', fn ($app, array $config) => new CaseInsensitiveUserProvider($app['hash'], $config['model']));
        $this->app->extend('validation.presence', fn ($verifier, $app) => new CaseInsensitivePresenceVerifier($app['db']));
        $this->app->afterResolving('validator', fn ($factory, $app) => $factory->setPresenceVerifier($app['validation.presence']));
        if ($this->app->resolved('validator')) {
            $this->app['validator']->setPresenceVerifier($this->app['validation.presence']);
        }

        // P3-1: OAuth2 scopes for third-party apps (authorization_code grant).
        // Partner code runs on the PARTNER's servers; these scopes are the only
        // reach it has into tenant data. Names mirror the ApiToken abilities
        // (resource:action) so route gates read the same way everywhere.
        // Polish (feature-platform P5-3): dual-secret grace on rotation.
        $this->app->bind(
            ClientRepository::class,
            DualSecretClientRepository::class,
        );

        // P1-00a (Passport 13): oauth_clients keeps bigint ids (no UUID switch) and
        // our plain-secret client model (see Juwri\Modules\Auth\Models\OAuthClient).
        Passport::$clientUuids = false;
        Passport::useClientModel(\Juwri\Modules\Auth\Models\OAuthClient::class);

        Passport::tokensCan([
            'menu:read' => 'Read menus, categories and items',
            'menu:write' => 'Create and update items items',
            'orders:read' => 'Read orders and their status',
            'orders:write' => 'Create and update orders',
            'analytics:read' => 'Read scan and sales analytics',
            'restaurant:read' => 'Read restaurant profile and settings',
            // P1-16: an MCP client's grant (owner MCP server); its other scopes are the tool abilities.
            'mcp:use' => 'Use your account through an AI assistant (MCP)',
        ]);
        Passport::setDefaultScope(['menu:read']);

        // Short-lived access, longer refresh — partners re-authorize silently.
        Passport::tokensExpireIn(now()->addHours(12));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::authorizationView('oauth.authorize');
    }
}
