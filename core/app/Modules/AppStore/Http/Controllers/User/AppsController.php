<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\User;

use App\Models\App;
use App\Models\AppInstallation;
use App\Services\AppInstallService;
use App\Services\AppSessionToken;
use Illuminate\Http\RedirectResponse;
use Juwri\Core\Http\Controllers\Controller;
use Laravel\Passport\Passport;

/**
 * P3-2: owner-facing "Installed apps" — list approved apps, install/uninstall.
 * Modelled on the Integrations page pattern (per-owner rows, explicit toggle
 * actions). Only `approved` apps ever appear; scope grant happens here (the
 * OAuth consent screen re-confirms per-token).
 */
class AppsController extends Controller
{
    public function page()
    {
        $apps = App::where('status', 'approved')->with('icon')->orderBy('name')->get();
        $installations = AppInstallation::with('app')
            ->where('user_id', auth()->id())
            ->get()
            ->keyBy('app_id');

        // Human-readable scope labels from the Passport registry (same surface
        // as the public App Store detail page); unknown keys render raw.
        $scopeLabels = collect(Passport::scopes())->keyBy('id')->map->description;

        return view('owner.marketplace.connected-apps', compact('apps', 'installations', 'scopeLabels'));
    }

    public function install(int $appId, AppInstallService $service)
    {
        $app = App::where('status', 'approved')->findOrFail($appId);
        $installation = $service->install($app, auth()->user());

        return response()->json([
            'ok' => true,
            'status' => $installation->status,
            'granted_scopes' => $installation->granted_scopes,
        ]);
    }

    public function uninstall(int $appId, AppInstallService $service)
    {
        // ST5: a suspended install (failed renewal) is still uninstallable.
        $installation = AppInstallation::where('app_id', $appId)
            ->where('user_id', auth()->id())
            ->whereIn('status', ['active', 'suspended'])
            ->firstOrFail();

        $service->uninstall($installation);

        return response()->json(['ok' => true, 'status' => 'uninstalled']);
    }

    /** P3-3: an installed embedded app, rendered in a dashboard iframe. */
    public function embed(int $appId)
    {
        $app = App::where('status', 'approved')->whereNotNull('embed_url')->findOrFail($appId);
        AppInstallation::where('app_id', $app->id)
            ->where('user_id', auth()->id())->where('status', 'active')->firstOrFail();

        return response()
            ->view('owner.stores.app-embed', compact('app'))
            // Clickjacking guard on the host page itself; the APP's server must
            // in turn allow US as its frame ancestor.
            ->header('Content-Security-Policy', "frame-ancestors 'self'");
    }

    /** P3-3: mint a 60-second session token for the embedded app (App-Bridge). */
    public function sessionToken(int $appId)
    {
        $app = App::where('status', 'approved')->findOrFail($appId);
        AppInstallation::where('app_id', $app->id)
            ->where('user_id', auth()->id())->where('status', 'active')->firstOrFail();

        return response()->json([
            'token' => AppSessionToken::mint($app, auth()->user()),
            'expires_in' => AppSessionToken::TTL_SECONDS,
        ]);
    }

    /**
     * P5-2 (feature-platform): scope re-consent. P1-13 13h: moved here from PartnerAppController (partner
     * publishing is ee; re-consenting to an installed app's new scopes is part of installing = Community).
     * Installations keep the scopes
     * granted at install time; when an approved version EXPANDS the app's
     * scopes, each owner explicitly accepts before their install upgrades.
     */
    public function reconsent(int $app): RedirectResponse
    {
        $app = App::where('status', 'approved')->findOrFail($app);
        $installation = AppInstallation::where('app_id', $app->id)
            ->where('user_id', auth()->id())->where('status', 'active')->firstOrFail();

        $installation->update(['granted_scopes' => $app->scopes]);
        log_activity('app.scopes_reconsented', auth()->user(), ['app' => $app->slug], null);

        app_alert_success(___('Updated permissions accepted.'));

        return back();
    }
}
