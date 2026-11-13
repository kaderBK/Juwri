<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Owner mobile app: deep-link association files served from the main domain.
 *
 *  - /.well-known/apple-app-site-association  (iOS universal links + password autofill)
 *  - /.well-known/assetlinks.json             (Android app links + credential sharing)
 *
 * Both 404 until the identity is configured (MOBILE_APP_APPLE_TEAM_ID /
 * MOBILE_APP_ANDROID_SHA256), so an install without a published app never
 * claims links for an app that does not exist. Apple fetches the file without
 * following redirects, so these must stay plain 200 JSON responses.
 */
class OwnerAppLinksController extends Controller
{
    public function appleAppSiteAssociation(): JsonResponse
    {
        $teamId = trim((string) config('mobile_app.apple_team_id', ''));
        abort_if($teamId === '', 404);

        $appId = $teamId.'.'.config('mobile_app.app_id');

        return response()->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => [$appId],
                    'components' => array_map(
                        fn (string $path) => ['/' => $path],
                        (array) config('mobile_app.deep_link_paths', [])
                    ),
                ]],
            ],
            // Lets the native login screen offer the owner's saved web password.
            'webcredentials' => ['apps' => [$appId]],
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }

    public function assetLinks(): JsonResponse
    {
        $fingerprints = (array) config('mobile_app.android_sha256_fingerprints', []);
        abort_if($fingerprints === [], 404);

        return response()->json([[
            'relation' => [
                'delegate_permission/common.handle_all_urls',
                'delegate_permission/common.get_login_creds',
            ],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => (string) config('mobile_app.app_id'),
                'sha256_cert_fingerprints' => array_values($fingerprints),
            ],
        ]], 200, [], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Server item S6: Hotwire Native historical-location routes. The app's path
     * configuration acts on these URLs (pop / stay / refresh); a plain browser
     * that follows one just gets a blank page.
     *
     * The body must NOT be empty: when a Turbo form submission is redirected here
     * (OwnerApp::historicalRedirect), Turbo only proposes the visit — the thing the
     * native path configuration reacts to — if the followed response has HTML.
     * An empty 200 made the redirect a silent no-op. (turbo-rails answers with a
     * short HTML string for the same reason.)
     */
    public function historicalLocation(Request $request): Response
    {
        // "refresh" reloads the screen underneath with a new request: keep the
        // form's flash message (its toast) alive for that request.
        if ($request->route()?->getName() === 'owner-app.historical.refresh' && $request->hasSession()) {
            $request->session()->reflash();
        }

        return response('<!DOCTYPE html><html><head><meta charset="utf-8"><title></title></head><body></body></html>', 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }
}
