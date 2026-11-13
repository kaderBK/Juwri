<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Providers;

use Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\Paginator;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Juwri\Core\Http\Middleware\StartSession;
use Juwri\Core\Models\User;
use Juwri\Core\Octane\BeginRequest;
use Juwri\Core\Octane\RequestAware;
use Juwri\Core\Routing\LocalizedRouteLookup;
use Juwri\Core\Services\Maps\MapsManager;
use Juwri\Core\Session\MergingSessionManager;
use Juwri\Core\Support\Html;
use Juwri\Core\Support\LocalizedRoutes;
use Juwri\Core\Support\SlowQueryLogger;
use Juwri\Modules\Auth\Models\Passkey;
use Juwri\Modules\Content\Services\Blog\Autopilot\AutopilotWriter;
use Juwri\Modules\Content\Services\Blog\Autopilot\WriterContract;
use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;
use Juwri\Modules\Fiscal\Services\Eta\EtaHttpClient;
use Juwri\Modules\Fiscal\Services\Eta\FakeEtaClient;
use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\FakeJofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraHttpClient;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaHttpClient;
use Juwri\Modules\Messaging\Services\StaffPush\FcmPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\VapidWebPushSender;
use Juwri\Modules\Messaging\Services\StaffPush\WebPushSender;
use Juwri\Modules\Verticals\Services\Skin\MockSkinScanProvider;
use Juwri\Modules\Verticals\Services\Skin\SkinScanProvider;
use Juwri\Modules\Verticals\Services\Stylist\MockStylistProvider;
use Juwri\Modules\Verticals\Services\Stylist\StylistProvider;
use Juwri\Modules\Verticals\Services\Vin\MockVinDecodeProvider;
use Juwri\Modules\Verticals\Services\Vin\VinDecodeProvider;
use Laravel\Passkeys\Passkeys;
use Mcamara\LaravelLocalization\LaravelLocalization;

class AppServiceProvider extends ServiceProvider implements RequestAware
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        /* Change the public path */
        app()->usePublicPath(base_path('../'));

        // P6-136: a session save keeps what concurrent requests wrote (lost locale / flash / cart race).
        $this->app->singleton('session', fn ($app) => new MergingSessionManager($app));
        // P6-146: StartSession that never sends a superseded (pre-login) session id back. Same wiring as the stock one.
        $this->app->singleton(StartSession::class, fn ($app) => new StartSession(
            $app->make(SessionManager::class),
            fn () => $app->make(CacheFactory::class),
        ));

        // P1-19 (J25-13): laravel/passkeys standalone. Its own routes stay off — juwri's PasskeyController
        // wraps the package actions so a passkey sign-in runs every password sign-in check.
        Passkeys::ignoreRoutes();
        Passkeys::useUserModel(User::class);
        Passkeys::usePasskeyModel(Passkey::class);

        // R3g: the old App\Mail\* names of the 3 mailables that moved (AppReviewResult is queued, so a job on the wire
        // may still carry it) are all Enterprise classes now: P1-13 13i moved their aliases into the Enterprise
        // package's legacy alias file (resolved by LegacyClassAliases; the package defines them up front when it registers,
        // so instanceof checks against the old names keep matching; a Community build has none).

        // theme-pack-blade F4+F5: maps/geocode manager. Singleton so the resolved
        // provider (and its cache) is shared per request; reads maps_provider +
        // maps_google_api_key from the Option store. osm is the working default.
        $this->app->singleton(MapsManager::class);

        // Blog Autopilot (Lane A): the writing-engine seam. GenerateBlogPostJob
        // and the surfaces resolve the contract; tests bind fakes over it.
        $this->app->bind(
            WriterContract::class,
            AutopilotWriter::class
        );

        // torque (car-parts theme) "Scan your VIN" seam. Mock decoder today —
        // resolves a VIN to a vehicle the store stocks parts for; swap to a real
        // provider (NHTSA vPIC / paid VIN API) by rebinding this one line.
        $this->app->bind(
            VinDecodeProvider::class,
            MockVinDecodeProvider::class
        );

        // maison (clothing theme) AI Style Search + Visual Search seam. Mock
        // matches against the store's own catalog today; swap for a real
        // vision/recommender provider by rebinding this one line.
        $this->app->bind(
            StylistProvider::class,
            MockStylistProvider::class
        );

        // lumiere (cosmetic theme) "Scan Your Face" seam. Mock analysis today —
        // plausible metrics + product picks from the store's catalog; swap for a
        // real vision provider by rebinding this one line.
        $this->app->bind(
            SkinScanProvider::class,
            MockSkinScanProvider::class
        );

        // TAX-RUN F0-P2 / J25-8: the fiscal adapter registry (FiscalDriverManager) is built by
        // FiscalServiceProvider from config('fiscal.adapters'); the authority clients stay here.

        // ETA client: Fake by default; live only under an env flag.
        $this->app->bind(EtaClient::class, function ($app) {
            if (config('services.eta.live') && ! $app->runningUnitTests()) {
                return new EtaHttpClient(
                    (string) config('services.eta.id_base'),
                    (string) config('services.eta.api_base'),
                    (string) config('services.eta.lookup_base'),
                );
            }

            return new FakeEtaClient;
        });

        // JoFotara client: Fake by default; live only under an env flag.
        $this->app->bind(JofotaraClient::class, function ($app) {
            if (config('services.jofotara.live') && ! $app->runningUnitTests()) {
                return new JofotaraHttpClient((string) config('services.jofotara.base_url'));
            }

            return new FakeJofotaraClient;
        });

        // ZATCA client: Fake by default; live HTTP client only under an explicit
        // env flag (env-flagged sandbox smoke — never in tests).
        $this->app->bind(ZatcaClient::class, function ($app) {
            if (config('services.zatca.live') && ! $app->runningUnitTests()) {
                return new ZatcaHttpClient(ZatcaHttpClient::resolveBaseUrl());
            }

            return new FakeZatcaClient;
        });

        /* PARITY-RUN T9 — THE push backfill.
         *
         * `StaffPushService::__construct` has always ended in
         * `app()->bound(WebPushSender::class) ? … : new NullWebPushSender()`, and
         * until now NOTHING bound that interface anywhere in the app. The null
         * sender — which reports success and delivers nothing — was live in
         * production, so staff push has never actually sent a notification.
         *
         * The binding is the multi-channel sender: native FCM/APNs device tokens
         * go to Firebase (BYOK per store, see PushCredentials), web-push
         * subscriptions go to the real VAPID sender. Both degrade to a safe
         * no-op when their credentials are absent — an unconfigured install
         * behaves exactly as it did before, WITHOUT pruning anybody's
         * subscription. Regression guard: Tests\Modules\Messaging\MobilePushBindingTest.
         */
        $this->app->bind(WebPushSender::class, function () {
            return new FcmPushSender(
                new VapidWebPushSender
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Worker mode (Octane / route:cache): route('x') must answer the current request's language prefix, as it does under
        // php-fpm where only that language's routes exist. After every provider has booted, so it also wins over the
        // url->setRoutes() that loading cached routes does.
        $this->app->booted(function () {
            if (LocalizedRoutes::registered() === []) {
                return;
            }
            $this->app['url']->setRoutes(new LocalizedRouteLookup(
                $this->app['router']->getRoutes(),
                function (): ?string {
                    $segment = strtolower((string) request()->segment(1));

                    return $segment !== '' && in_array($segment, LocalizedRoutes::registered(), true) ? $segment : null;
                }
            ));
        });

        // @clean($value)            → sanitize with the 'default' (inline-only) profile
        // @clean($value, 'richtext') → sanitize with the richer profile
        // The ONLY sanctioned way to echo user/DB HTML in a view. See Juwri\Core\Support\Html.
        Blade::directive('clean', function ($expression) {
            return "<?php echo \Juwri\Core\Support\Html::clean($expression); ?>";
        });

        // Resilience: a backstop timeout for EVERY outbound Http:: call. Guzzle's
        // default timeout is 0 (infinite), so a slow-but-alive upstream (payment
        // gateway, SMS, Turnstile, …) could pin a php-fpm worker forever and
        // exhaust the pool → whole-site 503. globalOptions applies as the default;
        // any call that needs a tighter (or looser) bound still wins via its own
        // ->timeout()/->connectTimeout(). 30s is a safe ceiling, not a target.
        Http::globalOptions([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        // P0-6: loud warning if the app boots with debug on in production.
        if (function_exists('app_debug_in_production') && app_debug_in_production()) {
            logger()->warning('security: APP_DEBUG is enabled in production — disable it to avoid leaking secrets.');
        }

        // P1-17: relationship loading guards. Only local and testing throw on a lazy load, so an N+1
        // fails while it is written. Every other environment (production, staging, ...) quietly
        // eager-loads a relation for the whole result set the first time it is touched, and never throws.
        if ($this->app->environment(['local', 'testing'])) {
            Model::preventLazyLoading();
        } else {
            Model::automaticallyEagerLoadRelationships();
        }

        // S8: shared password policy. Strict (flag PASSWORD_POLICY_STRICT) demands
        // 10+ mixed-case-with-number, breach-checked passwords; default keeps the
        // historical min-6 so nobody is locked out until the owner flips it.
        Password::defaults(function () {
            return config('auth.password_policy_strict')
                ? Password::min(10)->mixedCase()->numbers()->uncompromised()
                : Password::min(6);
        });

        // O12c: slow-query logging — mysql only, so the sqlite test suite pays
        // nothing (the listener is not even registered under sqlite). The
        // per-query gate + threshold live in SlowQueryLogger.
        if (config('database.connections.'.config('database.default').'.driver') === 'mysql') {
            DB::listen(
                fn (QueryExecuted $query) => SlowQueryLogger::handle($query)
            );
        }

        // Heartbeat consumed by queue_worker_alive(): lets web requests know a
        // queue worker daemon is actually draining jobs, so slow AI jobs can
        // fall back to dispatchAfterResponse() on hosts with no worker/cron.
        // Throttled: an idle worker loops every ~3s and the value only needs
        // 180s freshness — no point rewriting the (file) cache each loop.
        // Stamping itself (both keys + the daemon/fallback split) lives in
        // queue_worker_stamp_heartbeat() so the gate contract is testable.
        Queue::looping(function () {
            static $last = 0;
            if (time() - $last >= 60) {
                $last = time();
                queue_worker_stamp_heartbeat();
            }
        });

        // Pagination: the kit pager (vendor/pagination/juwri*, WI-16e) everywhere;
        // the Bootstrap pager left with the old-theme/NexLink themes (WI-16h).
        Paginator::defaultView('vendor.pagination.juwri');
        Paginator::defaultSimpleView('vendor.pagination.juwri-simple');

        $this->forRequest();
    }

    /**
     * The parts of boot() that depend on the request being served and on settings an admin can change: the active
     * locale list and the force-SSL flag. php-fpm runs them once per request (boot); an Octane worker boots once, so
     * {@see BeginRequest} runs them again at the start of every request.
     */
    public function forRequest(): void
    {
        if (config('settings.include_language_code')) {
            $supportedLocales = Config::get('laravellocalization.supportedLocales', []);

            // Console tooling must be able to boot before MySQL is reachable
            // (PHPStan, package discovery, config caching, first-time setup).
            // Web requests still use the database-backed active locale list.
            if (app_is_installed() && ! $this->app->runningInConsole()) {
                try {
                    // A worker lives across requests: a language switched on by another process (an admin request on
                    // another worker, the first-run seeders) only clears the memo there. Read this request's list.
                    $this->app->forgetInstance('memo.active_languages');
                    $activeLocales = get_active_languages();
                    if ($activeLocales !== []) {
                        $supportedLocales = $activeLocales;
                    }
                } catch (\Throwable $exception) {
                    logger()->warning('Unable to load active languages; using configured locales.', [
                        'exception' => $exception::class,
                    ]);
                }
            }

            Config::set('laravellocalization.supportedLocales', $supportedLocales);
            // The localization service memoises the list the first time it reads it. In an Octane worker it lives
            // across requests, so a language the admin (or the first-run seeders) switched on after the worker booted
            // made every page render 500 ("Locale 'ar' is not in the list of supported locales", found by the ZAP
            // scan of the Docker stack). Hand it this request's list.
            if ($this->app->resolved(LaravelLocalization::class)) {
                $this->app->make(LaravelLocalization::class)->setSupportedLocales($supportedLocales);
            }
        }

        if (config('settings.enable_force_ssl')) {
            // Force HTTPS everywhere EXCEPT the local preview host, so the
            // localhost dev server (php -S 127.0.0.1:8765) can serve assets
            // over http. The real domain and production are unaffected.
            $request = app('request'); // the CURRENT request (in an Octane worker $this->app is the base application)
            $host = $request->getHost();
            if (! in_array($host, ['localhost', '127.0.0.1'], true)) {
                $request->server->set('HTTPS', true);
            }
        }
    }
}
