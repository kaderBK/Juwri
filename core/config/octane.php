<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

/*
 * Phase 2 / item 16 (optional): Laravel Octane on FrankenPHP — the self-host Docker image runs `octane:frankenphp`.
 * php-fpm, Herd and `artisan serve` keep working unchanged: nothing here is read unless Octane is started.
 * The worker-safety audit lives in the internal run notes; the guard test is
 * tests/Feature/OctaneWorkerSafetyTest.php.
 */
// Octane is optional: before `composer install` has fetched laravel/octane (an older vendor folder, Herd) this file must
// still load, so php-fpm keeps working untouched.
if (! class_exists(Octane::class)) {
    return ['server' => 'frankenphp'];
}

return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'https' => (bool) env('OCTANE_HTTPS', false),

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            \Juwri\Core\Octane\BeginRequest::class, // new RequestEpoch + platform settings into this request's config
        ],

        RequestHandled::class => [],

        RequestTerminated::class => [],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            \Juwri\Core\Octane\BeginRequest::class,
        ],

        TaskTerminated::class => [],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            \Juwri\Core\Octane\BeginRequest::class,
        ],

        TickTerminated::class => [],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // DisconnectFromDatabases::class,
            CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
     * Services resolved once when a worker starts (shared by every request): only stateless ones.
     */
    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    /*
     * Singletons dropped before every request, so they are built again for it. They hold per-store, per-request or
     * per-visitor state (host => store maps, facts of the store being served, the shopper's bag, the reseller of the
     * host...), or package state that follows the request (the current locale); a worker that kept one would serve the
     * next request the previous one's. Names of classes that are not installed (the Enterprise package's) are ignored.
     */
    'flush' => [
        \Juwri\Modules\Storefront\Services\StoreAddressResolver::class,
        \Juwri\Modules\Fiscal\Services\FiscalDriverManager::class,
        \Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets::class,
        \Juwri\Modules\Agents\Tools\AgentToolRegistry::class,
        \Juwri\Modules\Payments\Contracts\Outbox::class,
        \Juwri\Core\Services\Maps\MapsManager::class,
        \Juwri\Core\Services\EntitlementGates::class,
        \Juwri\Core\Contracts\Billing\FeatureGate::class,
        \Juwri\Core\Contracts\Billing\PlanLimits::class,
        \Juwri\Core\Contracts\Billing\RequestMeter::class,
        \Juwri\Core\Contracts\Marketplace\ShopperBag::class,
        \Juwri\Core\Contracts\Marketplace\Directory::class,
        \Juwri\Core\Contracts\Tenancy\ResellerContext::class,
        \Juwri\Core\Contracts\Tenancy\ResellerSite::class,
        \Juwri\Core\Contracts\Content\ResellerContent::class,
        'csp-nonce',
        \Illuminate\Foundation\Vite::class, // stamped with the request's CSP nonce when it is resolved
        'laravellocalization',
        \Mcamara\LaravelLocalization\LaravelLocalization::class,
        \Laravel\Pennant\FeatureManager::class,
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'tables' => [],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

    /* Collect garbage after this many MB of growth. */
    'garbage' => 50,

    /* A request running longer than this many seconds is stopped. */
    'max_execution_time' => 30,

];
