<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Octane;

use Juwri\Core\Providers\SettingsServiceProvider;
use Juwri\Core\Support\RequestEpoch;

/**
 * Phase 2 / item 16: first Octane listener of every request, task and tick (config/octane.php).
 *
 *  1. {@see RequestEpoch::bump()}: every static memo stamped with the previous unit of work is stale (they re-read).
 *  2. The providers whose boot() depends on the request or on admin-editable settings ({@see RequestAware}: platform
 *     settings into config, active locales, force-SSL, view globals) run that part again for this request.
 *
 * Runs after Octane's own CreateConfigurationSandbox (the request's config clone), so what it writes dies with the request.
 */
final class BeginRequest
{
    public function handle(object $event): void
    {
        RequestEpoch::bump();

        $sandbox = $event->sandbox ?? app();

        // The "is this site installed" answer is kept on the container once asked; a "no" must not outlive the request
        // (the base application may have asked before the site was installed).
        if ($sandbox->bound('setup.complete') && ! $sandbox->make('setup.complete')) {
            $sandbox->forgetInstance('setup.complete');
        }

        // Shared view data the controllers / middleware set for one page.
        view()->share('pageFeatureKey', null);

        foreach ([SettingsServiceProvider::class, \App\Providers\AppServiceProvider::class] as $class) {
            $provider = $sandbox->getProvider($class);
            if ($provider instanceof RequestAware) {
                $provider->forRequest();
            }
        }
    }
}
