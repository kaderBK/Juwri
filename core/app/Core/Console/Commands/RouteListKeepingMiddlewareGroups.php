<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Foundation\Console\RouteListCommand;
use Illuminate\Routing\Router;

/**
 * `artisan route:list` flushes the router's middleware groups so it can print the raw group names. Once the HTTP
 * kernel has already synced them to the router (Laravel Pulse pulls in Livewire, which resolves the kernel at boot),
 * nothing puts them back, so a request served later in the same process (the test suite, a long-lived worker)
 * resolved the `web` group name as a class: "Target class [web] does not exist". We put the groups back when the
 * listing ends. Same output as the framework command.
 */
class RouteListKeepingMiddlewareGroups extends RouteListCommand
{
    public function __construct(private readonly Router $groupsRouter)
    {
        parent::__construct($groupsRouter);
    }

    public function handle()
    {
        $groups = $this->groupsRouter->getMiddlewareGroups();

        try {
            parent::handle();
        } finally {
            foreach ($groups as $name => $middleware) {
                $this->groupsRouter->middlewareGroup($name, $middleware);
            }
        }
    }
}
