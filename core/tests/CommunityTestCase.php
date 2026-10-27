<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests;

use App\Services\Backup\StoreBackupService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Juwri\Core\Http\Middleware\EditionSlot;
use Juwri\Core\Http\Middleware\EnforceHostSplit;
use Juwri\Core\Providers\QueueRoutingServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobClasses;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Core\Support\ScheduleSlots;
use Juwri\Core\Support\TrustedHostSources;

/**
 * P1-13 13c: a test that boots the app as the Community edition — no licence is injected, so an installed edition
 * package registers none of its modules (exactly what a Community install without a licence, or without the package,
 * runs).
 *
 * Providers fill process-wide registries at boot (route slots, hooks, schedule/middleware slots, store-data and job
 * classifications, backup extensions, dynamic Eloquent relations). A licensed test that ran earlier in the same
 * worker process leaves the ee modules' entries there, so they are emptied before this app boots; every provider
 * (Community ones included) registers again on boot. (PHPUnit process isolation is not usable under Pest.)
 */
abstract class CommunityTestCase extends TestCase
{
    /** Process-wide registries: class => [static property => empty value]. */
    private const REGISTRIES = [
        Hooks::class => ['hooks' => []],
        ModuleRoutes::class => ['slots' => []],
        ScheduleSlots::class => ['slots' => []],
        ModuleSeeders::class => ['modules' => []],
        TrustedHostSources::class => ['sources' => []],
        EditionSlot::class => ['slots' => []],
        StoreDataRegistry::class => ['modules' => [], 'tables' => null],
        TenantJobClasses::class => ['platform' => [], 'tenant' => []],
        StoreBackupService::class => ['extensions' => []],
        Model::class => ['relationResolvers' => []],
        EnforceHostSplit::class => ['moduleDashboardMiddleware' => []],
        QueueRoutingServiceProvider::class => ['moduleRoutes' => []],
    ];

    public function createApplication(): Application
    {
        foreach (self::REGISTRIES as $class => $properties) {
            foreach ($properties as $property => $empty) {
                (new \ReflectionProperty($class, $property))->setValue(null, $empty);
            }
        }

        return parent::createApplication();
    }

    protected function loadsEnterpriseEdition(): bool
    {
        return false;
    }
}
