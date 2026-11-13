<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Messaging\Services\MobilePush\PushCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform Firebase (FCM HTTP v1) service account for native push — the
 * `mobile_push` vault row with store_id NULL.
 *
 * The owner mobile app always sends through this credential (its device tokens
 * belong to the platform's Firebase project); staff / driver apps use a store's
 * own BYOK row first and fall back to it. The pasted JSON is parsed, stored
 * encrypted and never echoed back: the screen only shows the project id and the
 * service account's client email.
 */
class MobilePushCredentialsController extends Controller
{
    public function index(): View
    {
        return view('admin.mobile-push.index', [
            'status' => PushCredentials::platformStatus(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'service_account' => ['required', 'string', 'max:8192'],
        ]);

        $parsed = PushCredentials::parseServiceAccount((string) $request->input('service_account'));
        if ($parsed === null) {
            return back()->withErrors([
                'service_account' => ___('That does not look like a Firebase service account file.'),
            ]);
        }

        Credential::updateOrCreate(
            ['store_id' => null, 'feature' => PushCredentials::FEATURE, 'provider' => 'firebase'],
            ['secrets' => $parsed, 'active' => true]
        );

        return back()->with('success', ___('Firebase credentials saved.'));
    }

    public function destroy(): RedirectResponse
    {
        Credential::whereNull('store_id')
            ->where('feature', PushCredentials::FEATURE)
            ->delete();

        return back()->with('success', ___('Firebase credentials removed.'));
    }
}
