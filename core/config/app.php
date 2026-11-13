<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\E2eStubServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PluginsServiceProvider;
use App\Providers\RouteServiceProvider;
use App\Providers\ViewServiceProvider;
use Illuminate\Image\ImageServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Providers\CoreServiceProvider;
use Juwri\Core\Providers\CspServiceProvider;
use Juwri\Core\Providers\PulseServiceProvider;
use Juwri\Core\Providers\QueueRoutingServiceProvider;
use Juwri\Core\Providers\SearchServiceProvider;
use Juwri\Core\Providers\SettingsServiceProvider;
use Juwri\Modules\Accounting\Providers\AccountingServiceProvider;
use Juwri\Modules\Agents\Providers\AgentsServiceProvider;
use Juwri\Modules\Ai\Providers\AiServiceProvider;
use Juwri\Modules\Analytics\Providers\AnalyticsServiceProvider;
use Juwri\Modules\AppStore\Providers\AppStoreServiceProvider;
use Juwri\Modules\Biolink\Providers\BiolinkServiceProvider;
use Juwri\Modules\Bookings\Providers\BookingsServiceProvider;
use Juwri\Modules\Channels\Providers\ChannelsServiceProvider;
use Juwri\Modules\Content\Providers\ContentServiceProvider;
use Juwri\Modules\Customers\Providers\CustomersServiceProvider;
use Juwri\Modules\Delivery\Providers\DeliveryServiceProvider;
use Juwri\Modules\Fiscal\Providers\FiscalServiceProvider;
use Juwri\Modules\Hotel\Providers\HotelServiceProvider;
use Juwri\Modules\Hr\Providers\HrServiceProvider;
use Juwri\Modules\Integrations\Providers\IntegrationsServiceProvider;
use Juwri\Modules\Inventory\Providers\InventoryServiceProvider;
use Juwri\Modules\Kitchen\Providers\KitchenServiceProvider;
use Juwri\Modules\Laundry\Providers\LaundryServiceProvider;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\Loyalty\Providers\LoyaltyServiceProvider;
use Juwri\Modules\Marketing\Providers\MarketingServiceProvider;
use Juwri\Modules\Media\Providers\MediaServiceProvider;
use Juwri\Modules\Menu\Providers\MenuServiceProvider;
use Juwri\Modules\Messaging\Providers\MessagingServiceProvider;
use Juwri\Modules\Ordering\Providers\OrderingServiceProvider;
use Juwri\Modules\Payments\Providers\PaymentsServiceProvider;
use Juwri\Modules\Pharmacy\Providers\PharmacyServiceProvider;
use Juwri\Modules\Pos\Providers\PosServiceProvider;
use Juwri\Modules\Reservations\Providers\ReservationsServiceProvider;
use Juwri\Modules\Storefront\Providers\StorefrontServiceProvider;
use Juwri\Modules\Tables\Providers\TablesServiceProvider;
use Juwri\Modules\Team\Providers\TeamServiceProvider;
use Juwri\Modules\Verticals\Providers\VerticalsServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    | The platform settings table may override this at boot (see
    | SettingsServiceProvider); the env value is the install default.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    | The install-wide default language comes from DEFAULT_LANGUAGE, which
    | also feeds the `default_language` key below.
    |
    */

    'locale' => env('DEFAULT_LANGUAGE', 'en'),

    'fallback_locale' => 'en',

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => 'file',
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Providers
    |--------------------------------------------------------------------------
    |
    | The app still boots through the classic HTTP/console kernels, so the
    | provider list lives here: the framework defaults plus our own.
    |
    */

    // P1-00a: Laravel 13 adds Illuminate\Image (deferred, binds "image"), which would
    // take over the container key Intervention Image v2 uses (Intervention\Image\Facades\Image).
    // Left out until the media pipeline moves to one image library.
    'providers' => ServiceProvider::defaultProviders()->except([
        ImageServiceProvider::class,
    ])->merge([
        // P1-03: schema macros (checkedString, publicId) + per-module migration folders.
        CoreServiceProvider::class,
        QueueRoutingServiceProvider::class, // P1-17: Queue::route() central queue routing
        PulseServiceProvider::class, // P1-17: Laravel Pulse (self-hosters' ops dashboard; superadmin only, off by default)
        SearchServiceProvider::class, // P1-17: Scout database engine (menu + customer search)
        // P1-20: store address resolver (store_domains, store_url()).
        StorefrontServiceProvider::class,
        // P1-08b: L1 module providers (what Core needs from them is wired there).
        // P1-08: Auth module provider (its public contracts).
        Juwri\Modules\Auth\Providers\AuthServiceProvider::class,
        PaymentsServiceProvider::class,
        FiscalServiceProvider::class,
        MessagingServiceProvider::class,
        MediaServiceProvider::class,
        AiServiceProvider::class,
        // P1-08c: L2 module providers.
        MenuServiceProvider::class,
        CustomersServiceProvider::class,
        HrServiceProvider::class,
        // P1-08d: L3 module providers.
        OrderingServiceProvider::class,
        ReservationsServiceProvider::class,
        BookingsServiceProvider::class, // P3-10
        // P1-08e: L4 module providers.
        PosServiceProvider::class,
        KitchenServiceProvider::class,
        ChannelsServiceProvider::class,
        LoyaltyServiceProvider::class,
        InventoryServiceProvider::class,
        HotelServiceProvider::class,
        PharmacyServiceProvider::class,
        AccountingServiceProvider::class,
        // P1-08f: L5 module providers (Storefront is registered above; Platform plugins below).
        AnalyticsServiceProvider::class,
        AppStoreServiceProvider::class,
        BiolinkServiceProvider::class,
        ContentServiceProvider::class,
        IntegrationsServiceProvider::class,
        MarketingServiceProvider::class,
        // P1-09: providers of modules that only register routes.
        AgentsServiceProvider::class,
        DeliveryServiceProvider::class,
        LaundryServiceProvider::class,
        TablesServiceProvider::class,
        TeamServiceProvider::class,
        VerticalsServiceProvider::class,
        // J52b: LegacyCompat (off by default); its routes come from routes/web.php only when legacy_compat.enabled.
        LegacyCompatServiceProvider::class,
        SettingsServiceProvider::class,
        AppServiceProvider::class,
        AuthServiceProvider::class,
        // P1-00a: CSP nonce + @nonce (own CSP layer, replaces spatie/laravel-csp).
        CspServiceProvider::class,
        PluginsServiceProvider::class,
        EventServiceProvider::class,
        RouteServiceProvider::class,
        ViewServiceProvider::class,
        // Deterministic gateway/AI stubs for the E2E runner. Self-guarded: a
        // no-op unless local/testing AND `app.e2e_stubs` is on.
        E2eStubServiceProvider::class,
    ])->toArray(),

    'aliases' => Facade::defaultAliases()->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Application-specific keys
    |--------------------------------------------------------------------------
    |
    | Everything below is read by our own code. Values that used to be read
    | with env() at runtime live here so they survive `config:cache`.
    |
    */

    // Setup gate shortcut: when true, app_is_installed() skips its
    // "APP_KEY set + migrations/options tables exist" check.
    'installed' => env('APP_INSTALLED', false),

    // GET /healthz: the detailed `checks` map is only returned when the
    // caller passes a matching ?token=. Production hides details when blank.
    'healthz_token' => env('HEALTHZ_TOKEN', ''),

    'theme' => env('THEME_NAME', 'juwri'),

    'version' => env('APP_VERSION'),

    // Admin URL prefix, demo-mode switch and the default language.
    'admin' => env('APP_ADMIN', 'admin'),

    'demo_mode' => env('DEMO_MODE', false),

    'default_language' => env('DEFAULT_LANGUAGE', 'en'),

    // Local translation workflow: when true (and never in production or under
    // the test runner), ___() / ___n() append a missing key to each
    // lang/<locale>/lang.php. Off by default so page renders never rewrite lang files.
    'lang_auto_register' => (bool) env('LANG_AUTO_REGISTER', false),

    // E2E runner switches; both stay false unless the runner exports them.
    'e2e_stubs' => env('E2E_STUBS', false),

    'e2e_stub_gateway_fail' => env('E2E_STUB_GATEWAY_FAIL', false),

];
