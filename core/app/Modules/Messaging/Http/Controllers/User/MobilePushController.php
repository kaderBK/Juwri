<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\User;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Messaging\Services\MobilePush\PushCredentials;

/**
 * PARITY-RUN T9 — owner-facing Settings → Mobile app & push.
 *
 * Firebase BYOK, per-event toggles, a test push and the registered-device list
 * with revoke. Tenant scoping is the shared ResolvesCurrentStore guard.
 *
 * TWO gate lanes, deliberately:
 *  - every method here except {@see registerDevice} sits behind
 *    `feature:mobile_app_push` middleware — legal, because these are
 *    dashboard-only routes no device or anonymous caller reaches;
 *  - {@see registerDevice} is called by the APP on every cold start and carries
 *    NO feature middleware. It checks `MobilePushService::enabled()` inside the
 *    controller instead. Putting middleware on a device endpoint 403s every
 *    install — the mistake this codebase has made three times.
 *
 * No credential ever leaves this controller: {@see PushCredentials::status()}
 * returns booleans and a project id, and the service-account field is
 * write-only — the form never re-renders what was stored.
 */
class MobilePushController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request)
    {
        $store = $this->currentRestaurant($request);

        return view('owner.marketing.mobile-push', [
            'rid' => $store->id,
            'store' => $store,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
            'credentials' => PushCredentials::status((int) $store->id),
            'toggles' => MobilePushService::toggles((int) $store->id),
            'events' => MobilePushService::EVENTS,
            'devices' => MobilePushService::devices((int) $store->id),
            'webPushReady' => (string) config('webpush.public_key') !== '',
        ]);
    }

    /** Per-event owner toggles. */
    public function saveSettings(Request $request)
    {
        $store = $this->currentRestaurant($request);

        $data = $request->validate([
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', MobilePushService::EVENTS)],
        ]);

        MobilePushService::saveToggles((int) $store->id, (array) ($data['events'] ?? []));

        return back()->with('success', ___('Notification settings saved.'));
    }

    /**
     * Store the Firebase service account in the encrypted vault. The raw JSON is
     * parsed, validated and discarded — it is never echoed back, never logged,
     * and never written to a settings/options row.
     */
    public function saveCredentials(Request $request)
    {
        $store = $this->currentRestaurant($request);

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
            ['store_id' => $store->id, 'feature' => PushCredentials::FEATURE, 'provider' => 'firebase'],
            ['secrets' => $parsed, 'active' => true]
        );

        return back()->with('success', ___('Firebase credentials saved.'));
    }

    /** Remove this store's own credentials; the platform default (if any) applies again. */
    public function clearCredentials(Request $request)
    {
        $store = $this->currentRestaurant($request);

        Credential::where('store_id', $store->id)
            ->where('feature', PushCredentials::FEATURE)
            ->delete();

        return back()->with('success', ___('Firebase credentials removed.'));
    }

    /** Send a test notification to every registered device of this store. */
    public function test(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $sent = MobilePushService::sendTest((int) $store->id);

        return back()->with('success', $sent > 0
            ? ___('Test notification sent to :count device(s).', ['count' => $sent])
            : ___('No app devices are registered for this store yet.'));
    }

    public function revokeDevice(Request $request, int $id)
    {
        $store = $this->currentRestaurant($request);
        MobilePushService::revokeDevice((int) $store->id, $id);

        return back()->with('success', ___('Device removed.'));
    }

    /**
     * Device registration from the owner app's WebView. Authenticated as the
     * dashboard user (the app logs in through the normal web session), tenant
     * checked the same way every other dashboard write is — but gated in the
     * BODY, not by middleware, because this is a device transport.
     */
    public function registerDevice(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        if (! MobilePushService::enabled($rid)) {
            return response()->json(['success' => false, 'error' => 'feature_disabled'], 403);
        }

        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:fcm,apns'],
            'device_name' => ['nullable', 'string', 'max:80'],
            'app_version' => ['nullable', 'string', 'max:24'],
            'os_version' => ['nullable', 'string', 'max:24'],
        ]);

        MobilePushService::registerDevice(
            $rid,
            $data['token'],
            $data['platform'],
            [
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'os_version' => $data['os_version'] ?? null,
            ],
            null,
            (int) (Auth::id() ?: 0) ?: null
        );

        return response()->json(['success' => true]);
    }
}
