<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Providers;

use Illuminate\Foundation\Console\RouteListCommand;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Console\Commands\RouteListKeepingMiddlewareGroups;
use Laravel\Pulse\Facades\Pulse;

/**
 * P1-17: Laravel Pulse for self-hosters. config/pulse.php holds the switches (off by default, cheap sampled recorders,
 * superadmin-only middleware); the `viewPulse` gate is PanelAbilities::PULSE (superadmin). Whatever Pulse shows for a
 * user is the id only: no name, email or avatar (no Gravatar lookups) ever reaches the page.
 */
class PulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Why: see RouteListKeepingMiddlewareGroups (Pulse -> Livewire resolves the HTTP kernel at boot).
        $this->app->extend(RouteListCommand::class, fn ($command, $app) => new RouteListKeepingMiddlewareGroups($app['router']));
    }

    public function boot(): void
    {
        Pulse::user(fn ($user): array => ['name' => '#'.$user->getKey(), 'extra' => '', 'avatar' => '']);
    }
}
