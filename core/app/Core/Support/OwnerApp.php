<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * Owner mobile app (Hotwire Native shell) — the server-side knowledge the shell needs:
 * is this request coming from the app, is the app new enough, and which native
 * tabs should a given store get.
 *
 * The shell renders the live dashboard in a WebView; tabs are only shortcuts into
 * existing dashboard routes. Paths are RELATIVE and built from route names, so a
 * renamed URL follows the route table instead of silently breaking the app.
 */
class OwnerApp
{
    public const PLATFORMS = ['ios', 'android'];

    /** Is the current request coming from inside the owner app's WebView? */
    public static function inApp(?Request $request = null): bool
    {
        $request ??= request();
        $token = (string) config('mobile_app.user_agent_token', 'JuwriApp');

        return $token !== '' && str_contains((string) $request->userAgent(), $token.'/');
    }

    /** "1.4.2" from "... JuwriApp/1.4.2 (ios)", or null outside the app. */
    public static function appVersion(?Request $request = null): ?string
    {
        $request ??= request();
        $token = preg_quote((string) config('mobile_app.user_agent_token', 'JuwriApp'), '/');

        return preg_match('/'.$token.'\/([0-9]+(?:\.[0-9]+){0,3})/', (string) $request->userAgent(), $m) ? $m[1] : null;
    }

    /** Normalise a client-supplied platform; anything unknown is null. */
    public static function platform(?string $platform): ?string
    {
        $platform = strtolower(trim((string) $platform));

        return in_array($platform, self::PLATFORMS, true) ? $platform : null;
    }

    /** Push-subscription platform for a device OS (the token is always an FCM token). */
    public static function pushPlatform(string $platform): string
    {
        return $platform === 'ios' ? 'apns' : 'fcm';
    }

    /**
     * Version gate for one platform: `update_required` when the shell is older
     * than min_version, `update_available` when older than latest_version.
     *
     * @return array{min_version:string,latest_version:string,store_url:string,update_required:bool,update_available:bool}
     */
    public static function versionInfo(string $platform, ?string $current): array
    {
        $min = (string) config("mobile_app.min_version.$platform", '1.0.0');
        $latest = (string) config("mobile_app.latest_version.$platform", $min);
        $valid = $current !== null && preg_match('/^[0-9]+(\.[0-9]+){0,3}$/', $current) === 1;

        return [
            'min_version' => $min,
            'latest_version' => $latest,
            'store_url' => (string) config("mobile_app.store_urls.$platform", ''),
            'update_required' => $valid && version_compare($current, $min, '<'),
            'update_available' => $valid && version_compare($current, $latest, '<'),
        ];
    }

    /**
     * The owner's locale + text direction, so the shell mirrors its chrome for RTL.
     *
     * @return array{locale:string,dir:string}
     */
    public static function localeFor(User $user): array
    {
        $code = (string) ($user->preferred_locale ?: app()->getLocale() ?: 'en');
        $direction = (string) (Language::where('code', $code)->value('direction') ?: 'ltr');

        return ['locale' => $code, 'dir' => $direction === 'rtl' ? 'rtl' : 'ltr'];
    }

    /**
     * The five native tabs for a store. The second and third tabs follow the
     * store's vertical group: a restaurant gets Orders + Menu, a shop gets its
     * order board + Products, a laundry its service board + Services, a hotel
     * its booking calendar + Rooms.
     *
     * @return list<array{key:string,label:string,icon:string,path:?string}>
     */
    public static function tabsFor(Store $store): array
    {
        $id = (int) $store->id;
        $group = (string) ($store->verticalConfig()['group'] ?? 'food');

        [$work, $catalog] = match ($group) {
            'goods' => [
                ['orders', ___('Orders'), 'receipt', self::path('stores.orders.board', ['store_id' => $id])],
                ['catalog', ___('Products'), 'package', self::path('retail.products.index', ['store' => $id])],
            ],
            'services' => [
                ['orders', ___('Orders'), 'receipt', self::path('stores.service_board.index', ['store_id' => $id])],
                ['catalog', ___('Services'), 'package', self::path('laundry.services.index', ['store' => $id])],
            ],
            'stays' => [
                ['orders', ___('Bookings'), 'calendar', self::path('hotel.pms.calendar', ['store_id' => $id])],
                ['catalog', ___('Rooms'), 'bed', self::path('hotel.rooms', ['store_id' => $id])],
            ],
            default => [
                ['orders', ___('Orders'), 'receipt', self::path('stores.orders', ['store' => $id])],
                ['catalog', ___('Menu'), 'book-open', self::path('stores.menu', ['store' => $id])],
            ],
        };

        $tabs = [
            ['home', ___('Home'), 'home', self::path('dashboard', ['store_id' => $id])],
            $work,
            $catalog,
            ['reports', ___('Reports'), 'chart', self::path('reports.index', ['store_id' => $id])],
            // `more` has no path: the shell opens its native sheet built from moreFor().
            ['more', ___('More'), 'menu', null],
        ];

        return array_map(fn (array $t) => [
            'key' => $t[0], 'label' => $t[1], 'icon' => $t[2], 'path' => $t[3],
        ], $tabs);
    }

    /**
     * The native "More" sheet: the next most-used owner screens. Entries whose
     * route does not exist on this install are dropped, never sent broken.
     *
     * @return list<array{key:string,label:string,icon:string,path:string}>
     */
    public static function moreFor(Store $store): array
    {
        $id = (int) $store->id;

        $items = [
            ['reservations', ___('Reservations'), 'calendar', 'reservations.index', ['store_id' => $id]],
            ['kds', ___('Kitchen display'), 'monitor', 'kds.index', ['store_id' => $id]],
            ['customers', ___('Customers'), 'users', 'customers.index', ['store_id' => $id]],
            ['reviews', ___('Reviews'), 'star', 'dashboard.store-reviews', ['store' => $id]],
            ['analytics', ___('Analytics'), 'chart', 'analytics.index', ['store_id' => $id]],
            ['notifications', ___('Notifications'), 'bell', 'dashboard.notifications', []],
            ['mobile_app', ___('Mobile app & push'), 'smartphone', 'stores.mobilepush.index', ['store_id' => $id]],
            ['store_settings', ___('Store settings'), 'settings', 'stores.edit', ['store' => $id]],
            // Profile + "Delete account" (Apple 5.1.1(v): deletion reachable from inside the app).
            ['account', ___('Account'), 'user', 'settings', []],
        ];

        $out = [];
        foreach ($items as [$key, $label, $icon, $route, $params]) {
            $path = self::path($route, $params);
            if ($path !== null) {
                $out[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'path' => $path];
            }
        }

        return $out;
    }

    /**
     * Relative path for a named route, or null when the route is not registered.
     *
     * @param  array<string, mixed>  $params
     */
    public static function path(string $name, array $params = []): ?string
    {
        if (! app('router')->has($name)) {
            return null;
        }

        try {
            return route($name, $params, false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Server item S6 helper — Laravel port of turbo-rails' recede_or_redirect_to /
     * resume_or_redirect_to / refresh_or_redirect_to. Finish a form that the app
     * opened as a modal: inside the app, redirect (303) to a historical-location
     * route and let the path configuration act — `recede` pops the modal (or the
     * screen), `resume` just dismisses the modal, `refresh` dismisses it and
     * reloads the screen underneath (flash messages survive only `refresh`). In a
     * browser, a plain redirect to $fallback, exactly as before.
     *
     * The request must be a Turbo form submission for the app to see it (in-app
     * forms are Turbo-driven, rev/mobile-app/FORMS-AUDIT.md).
     *
     * @param  'recede'|'resume'|'refresh'  $action
     */
    public static function historicalRedirect(string $action, string $fallback, ?Request $request = null): RedirectResponse
    {
        // An unknown action has no route, so it falls back to the browser redirect.
        if (self::inApp($request) && app('router')->has('owner-app.historical.'.$action)) {
            return redirect()->route('owner-app.historical.'.$action, [], 303);
        }

        return redirect()->to($fallback);
    }

    /** Bump when path-configuration rules change (apps cache the last copy). */
    public const PATH_CONFIG_VERSION = 2;

    /**
     * Hotwire Native path configuration (spec §3.4, server item S1). Served per
     * platform; the apps bundle a copy and refresh from the server on launch,
     * so rules change without a store release.
     *
     * @return array{settings: array<string, mixed>, rules: list<array{patterns: list<string>, properties: array<string, mixed>}>}
     */
    public static function pathConfiguration(string $platform): array
    {
        $android = $platform === 'android';
        $web = $android ? ['uri' => 'hotwire://fragment/web'] : [];
        $modal = $android ? ['uri' => 'hotwire://fragment/web/modal/sheet'] : ['modal_style' => 'large'];
        $login = $android ? ['uri' => 'hotwire://fragment/login'] : ['view_controller' => 'login'];

        return [
            'settings' => [
                'version' => self::PATH_CONFIG_VERSION,
                'account_deletion_path' => self::path('user.account.delete.show'),
            ],
            'rules' => [
                // Every dashboard URL: a pushed native screen showing the web page.
                ['patterns' => ['.*'], 'properties' => ['context' => 'default', 'pull_to_refresh_enabled' => true] + $web],
                // Create / edit forms open as modal sheets (no pull-to-refresh: it would wipe input).
                ['patterns' => ['/new$', '/create$', '/edit$', '/new\\?', '/create\\?', '/edit\\?'], 'properties' => ['context' => 'modal', 'pull_to_refresh_enabled' => false] + $modal],
                // Session lost / login → the native login screen (it re-mints the web session from the token).
                // `default` context: Hotwire Native Android throws on a `modal` + `replace_root` rule.
                ['patterns' => ['^/login', '^/[a-z]{2}/login'], 'properties' => ['context' => 'default', 'presentation' => 'replace_root'] + $login],
                // Historical-location routes (turbo-rails port, server item S6).
                ['patterns' => ['/recede_historical_location$'], 'properties' => ['presentation' => 'pop']],
                ['patterns' => ['/resume_historical_location$'], 'properties' => ['presentation' => 'none']],
                ['patterns' => ['/refresh_historical_location$'], 'properties' => ['presentation' => 'refresh']],
            ],
        ];
    }
}
