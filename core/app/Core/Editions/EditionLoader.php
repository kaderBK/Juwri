<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions;

use Illuminate\Contracts\Foundation\Application;

/**
 * P1-13: loads the optional editions that are installed. The providers come from the edition packages' manifests
 * ({@see EditionPackages}); a provider whose class does not exist is skipped, and with no edition package installed
 * nothing happens and the app is Community. Licence checks live in the edition (its provider verifies before
 * registering anything).
 */
final class EditionLoader
{
    /** @param  list<string>|null  $providers  null = the installed edition packages' providers */
    public static function register(Application $app, ?array $providers = null): void
    {
        foreach ($providers ?? EditionPackages::providers() as $provider) {
            if ($provider !== '' && class_exists($provider)) {
                $app->register($provider);
            }
        }
    }
}
