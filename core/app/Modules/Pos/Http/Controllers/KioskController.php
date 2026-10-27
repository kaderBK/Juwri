<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers;

use App\Services\FeatureAccess;
use App\Support\KioskContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Pos\Services\Kiosk\KioskLoyalty;
use Juwri\Modules\Pos\Support\KioskPin;

/**
 * GAPS-RUN-2 B9-P1: PUBLIC kiosk web mode. A tablet at {slug}/kiosk shows a
 * full-screen attract screen wrapping the storefront in kiosk mode. Available only
 * when the kiosk feature is enabled (kill-switch + owner kiosk_enabled) — a random
 * visitor without setup gets 404. Staff exit + device setup are PIN-verified
 * server-side.
 */
class KioskController extends Controller
{
    private function resolve(string $slug): Store
    {
        $store = Store::query()->idOrSlug($slug)->firstOrFail();

        // F6-4: was kill-switch + owner toggle only, so a LAPSED kiosk entitlement
        // left the kiosk serving forever. `storeAllowsCached` is the same two-lane
        // check (kill veto always, entitlement when plan_gated) with a 60s TTL —
        // this is an unattended tablet polling in a loop. The subject is the store
        // OWNER: the visitor at the tablet never has an entitlement of their own.
        // 404 (not 403) is kept deliberately — this is an anonymous public URL and
        // the pre-existing contract is "nothing here"; whether a merchant pays is
        // not public information.
        abort_unless(
            FeatureAccess::storeAllowsCached((int) $store->id, 'kiosk')
                && ! empty(store_options($store->id, 'kiosk_enabled')),
            404
        );

        return $store;
    }

    public function show(Request $request, string $slug)
    {
        $store = $this->resolve($slug);

        // Server-side kiosk context: orders this browser session places at this
        // store are tagged source=kiosk (never from a client field).
        KioskContext::mark((int) $store->id);

        // I5-7: the attract screen is customer-facing in-store — render it in the
        // store's default menu language. P3-06: a start screen opened without a language (the idle return, a fresh
        // start) is the store's default again; the language buttons pick one of the store's menu languages.
        $default = (string) (store_options($store->id, 'default_language')
            ?: (config('settings.default_lang') ?: config('app.locale')));
        $wanted = $request->query('lang');
        $offered = array_map('strval', (array) store_options($store->id, 'menu_languages'));
        $lang = is_string($wanted) && in_array($wanted, $offered, true) ? $wanted : $default;
        app()->setLocale($lang);
        cookie()->queue(cookie('Quick_user_lang_code', $lang, 60 * 24 * 365, '/', null, null, false, false, 'lax'));
        $_COOKIE['Quick_user_lang_code'] = $lang;

        // P3-06: a store on a kit theme draws the start screen with that theme (Storefront fills this slot).
        $themed = Hooks::first('kiosk.start-view', $store, $request);
        if ($themed !== null) {
            return response($themed)->header('Cache-Control', 'no-store, private');
        }

        return view('kiosk.attract', [
            'store' => $store,
            'idleSeconds' => max(15, (int) (store_options($store->id, 'kiosk_idle_seconds') ?: 60)),
            'needsSetup' => KioskPin::isSet((int) $store->id, 'kiosk_setup_pin'),
            'orderUrl' => route('publicView', $store->slug).'?kiosk=1',
        ]);
    }

    /**
     * P3-06: a wrong PIN counts against the device (ip) and against the store; five misses in five minutes from one tablet, or
     * thirty in ten minutes at one store, answer 429 until the window passes. A right PIN clears the device's count.
     *
     * @return array{0: string, 1: string}|null the two limiter keys, or null when the caller is blocked
     */
    private function pinKeys(Request $request, Store $store, string $kind): ?array
    {
        $device = 'kiosk-'.$kind.':'.$store->id.':'.$request->ip();
        $storeKey = 'kiosk-'.$kind.'-store:'.$store->id;

        return RateLimiter::tooManyAttempts($device, 5) || RateLimiter::tooManyAttempts($storeKey, 30) ? null : [$device, $storeKey];
    }

    private function pinResponse(Request $request, Store $store, string $kind): JsonResponse
    {
        $keys = $this->pinKeys($request, $store, $kind);
        if ($keys === null) {
            return response()->json(['ok' => false, 'throttled' => true, 'retry_after' => 300], 429)->header('Retry-After', '300');
        }
        $input = $request->input('pin');
        $ok = KioskPin::verify((int) $store->id, 'kiosk_'.$kind.'_pin', is_scalar($input) ? (string) $input : ''); // P6-88: hash-only, legacy rehashed
        if ($ok) {
            RateLimiter::clear($keys[0]);
        } else {
            RateLimiter::hit($keys[0], 300);
            RateLimiter::hit($keys[1], 600);
        }

        return response()->json(['ok' => $ok]);
    }

    /** Verify the one-time device setup PIN. */
    public function setup(Request $request, string $slug)
    {
        $store = $this->resolve($slug);

        return $this->pinResponse($request, $store, 'setup');
    }

    /** The idle return: forget this device's guest cart so the next customer starts empty (kiosk mode stays on). */
    public function reset(Request $request, string $slug): JsonResponse
    {
        $store = $this->resolve($slug);
        Hooks::run('kiosk.session-reset', (int) $store->id);
        app(KioskLoyalty::class)->forget((int) $store->id, $request->session()); // item 26: the next customer is nobody

        return response()->json(['ok' => true]);
    }

    /** Verify the staff exit PIN (leaves kiosk mode). Server-verified, constant-time, throttled. */
    public function exit(Request $request, string $slug)
    {
        $store = $this->resolve($slug);
        $response = $this->pinResponse($request, $store, 'exit');
        if ($response->getData(true)['ok'] ?? false) {
            KioskContext::clear((int) $store->id);
            Hooks::run('kiosk.session-reset', (int) $store->id);
            app(KioskLoyalty::class)->forget((int) $store->id, $request->session());
        }

        return $response;
    }
}
