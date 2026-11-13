<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Modules\LegacyCompat\Console\LegacyCutoverCheckCommand;
use Juwri\Modules\LegacyCompat\Console\LegacyImportCommand;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaCopy;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaIndexCommand;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaVerify;
use Juwri\Modules\LegacyCompat\Console\LegacySeoLocalesCommand;
use Juwri\Modules\LegacyCompat\Console\LegacySlugConflictsCommand;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyCanonicalHost;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyGatewayNotice;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyStorefrontEntry;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyTwoFactorReenrol;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyGoogleWalletPassIds;
use Juwri\Modules\LegacyCompat\Support\LegacyHistoryGuard;
use Juwri\Modules\LegacyCompat\Support\LegacyHosts;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomCharge;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomTokenKeys;
use Juwri\Modules\LegacyCompat\Support\LegacySeo;
use Juwri\Modules\Loyalty\Contracts\GoogleWalletPassIds;

/**
 * J52b LegacyCompat module provider. With legacy_compat.enabled off (the default) it does nothing at all: no route
 * (routes come from Support\LegacyCompatRoutes::register(), called from routes/web.php), no middleware, no binding,
 * so a fresh install is unchanged (J52a). With the switch on, {@see activate()} wires:
 *
 * - the storefront entry middleware (old `?qr-id=` scans, table-key miss rate limit) onto the `web` group;
 * - a {@see LegacyRoomCharge} decorator around the bound RoomCharge so old-key room tokens verify (mint stays on
 *   the current key);
 * - a {@see LegacyGoogleWalletPassIds} decorator around the bound GoogleWalletPassIds so passes already saved on
 *   phones keep their ids (only when legacy_compat.google_wallet is fully configured);
 * - (console only) the M6-B media migration commands legacy:media-copy, legacy:media-verify and legacy:media-index.
 * - (console only) legacy:cutover-check: the read-only cut-over checklist of the import (it writes nothing).
 * - the {@see LegacyHistoryGuard}: imported orders, payments, billing and ledger rows stay read-only.
 * - the owner notices of migrated accounts: two-factor set-up again (J58b), store gateways switched off (J59e).
 *
 * - SEO parity: the old titles (LegacySeo), the canonical-origin redirect (LegacyCanonicalHost) and the
 *   `legacy:seo-locales` command (the old site's language set as install config).
 * - the old platform's hosts (legacy_compat.hosts) as extra storefront hosts of the J28 host map, and its imported
 *   store subdomains through the store resolver ({@see LegacyHosts}).
 *
 * All re-check the switch per call, so turning it off takes effect without a rebind.
 */
class LegacyCompatServiceProvider extends ServiceProvider
{
    /** Container flag: activate() already ran for this application instance. */
    public const ACTIVE_FLAG = 'legacy_compat.active';

    public function register(): void
    {
        $this->app->singleton(LegacyRoomTokenKeys::class, static fn (): LegacyRoomTokenKeys => new LegacyRoomTokenKeys);
    }

    public function boot(): void
    {
        // The one-off importer: always listed, refuses unless the module AND the import switch are on (ImportGuard).
        if ($this->app->runningInConsole()) {
            $this->commands([LegacyImportCommand::class, LegacySlugConflictsCommand::class, LegacySeoLocalesCommand::class]);
        }

        // The host-map sources are process statics: drop the ones an earlier application (a previous test, or a boot
        // with the switch on) registered, so they live exactly as long as this application's activation.
        LegacyHosts::unregister();

        if (LegacyCompatRoutes::enabled()) {
            self::activate($this->app);

            if ($this->app->runningInConsole()) {
                $this->commands([LegacyMediaCopy::class, LegacyMediaVerify::class, LegacyMediaIndexCommand::class, LegacyCutoverCheckCommand::class]);
            }
        }
    }

    /** Wire the module into a running application (boot with the switch on; tests after turning it on). */
    public static function activate(Application $app): void
    {
        if ($app->bound(self::ACTIVE_FLAG)) {
            return;
        }
        $app->instance(self::ACTIVE_FLAG, true);

        $app->make(Router::class)->pushMiddlewareToGroup('web', LegacyStorefrontEntry::class);
        // BL-035: www / plain http to the one canonical origin (config legacy_compat.seo.canonical_redirect, off by default).
        $app->make(Router::class)->prependMiddlewareToGroup('web', LegacyCanonicalHost::class);
        // BL-034: the old site's titles for the pages whose shipped copy differs (config legacy_compat.seo.title_parity).
        LegacySeo::applyTitles();

        LegacyHosts::register();

        $app->extend(RoomCharge::class, static function (RoomCharge $inner, Application $app): RoomCharge {
            return $inner instanceof LegacyRoomCharge ? $inner : new LegacyRoomCharge($inner, $app->make(LegacyRoomTokenKeys::class));
        });

        $app->extend(GoogleWalletPassIds::class, static function (GoogleWalletPassIds $inner): GoogleWalletPassIds {
            return $inner instanceof LegacyGoogleWalletPassIds ? $inner : new LegacyGoogleWalletPassIds($inner);
        });

        LegacyHistoryGuard::register();

        // J58b: migrated owners / staff who had two-factor on are asked to set it up again.
        $router = $app->make(Router::class);
        $router->pushMiddlewareToGroup('web', LegacyTwoFactorReenrol::class);
        $router->pushMiddlewareToGroup('api', LegacyTwoFactorReenrol::class);

        // J59e: owners whose store gateways were imported switched off are told once, on their dashboard.
        $router->pushMiddlewareToGroup('web', LegacyGatewayNotice::class);
    }
}
