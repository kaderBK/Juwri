<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Auth\Events\EmailChangeUndone;
use Juwri\Modules\Team\Listeners\DisableStaffOnEmailChangeUndo;

/**
 * Team module provider (P1-09): registers the module's route files.
 */
class TeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Team', new TeamStoreData);
        ModuleRoutes::register('Team', __DIR__.'/../Routes');
    }

    public function boot(): void
    {
        // BL-036 review: "This wasn't me — undo" switches off staff created or edited since the email change.
        Event::listen(EmailChangeUndone::class, DisableStaffOnEmailChangeUndo::class);
    }
}
