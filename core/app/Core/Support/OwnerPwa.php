<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Store;

/**
 * Owner dashboard PWA (rev/mobile-app/PWA-SPEC.md): installable dashboard,
 * owner web push, offline page and mobile-usage tracking helpers.
 */
class OwnerPwa
{
    /** Bump on ANY change to the service worker body (activate drops old caches). */
    public const SW_VERSION = 'owner-pwa-v3';

    /** Cookie the layout JS sets while the dashboard runs as an installed PWA. */
    public const STANDALONE_COOKIE = 'owner_pwa';

    public const ICON_SIZES = [192, 512];

    /** Manifest / status-bar colours: the redesign's top bar surface and page background (tokens.css). */
    public const CHROME_LIGHT = '#FFFFFF';

    public const CHROME_DARK = '#1B1B20';

    public const BACKGROUND = '#F4F2ED';

    /**
     * First path segments of the owner dashboard (PWA-SPEC §3.2): the service
     * worker answers navigations under these only, so storefronts (`/{slug}`),
     * marketing and admin pages are never touched. Derived from the routes that
     * carry the dashboard's `owner.screen` middleware, so a new dashboard area
     * is covered without a list to maintain.
     *
     * @return list<string>
     */
    public static function dashboardPrefixes(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        $segments = ['dashboard' => true];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            // Route-level middleware only (group middleware is merged into it):
            // gatherMiddleware() would instantiate every controller.
            if (! in_array('GET', $route->methods(), true) || ! in_array('owner.screen', (array) $route->middleware(), true)) {
                continue;
            }
            $first = explode('/', trim($route->uri(), '/'))[0];
            if ($first !== '' && ! str_starts_with($first, '{') && $first !== 'api') {
                $segments[$first] = true;
            }
        }
        $list = array_keys($segments);
        sort($list);

        return $memo = $list;
    }

    /**
     * Where a push tap should land for one order: the order's own screen
     * (`restaurants.orders.show` — status, next action, lines, customer), the
     * same for every vertical.
     *
     * Fallback when that route is absent: the store's Orders tab (per vertical,
     * same path as the native tab) focused on the order with `?order=`. Stays go
     * to the regular Orders page there, since their "orders" tab is the bookings
     * calendar, where an order (room service, extras) has no cell.
     */
    public static function orderUrl(int $storeId, int $orderId): string
    {
        if (Route::has('stores.orders.show')) {
            return route('stores.orders.show', ['order' => $orderId], false);
        }

        $store = Store::find($storeId);
        $path = null;
        if ($store && ($store->verticalConfig()['group'] ?? null) === 'stays' && Route::has('stores.orders')) {
            return route('stores.orders', ['store' => $storeId], false).'?order='.$orderId;
        }
        foreach ($store ? OwnerApp::tabsFor($store) : [] as $tab) {
            if ($tab['key'] === 'orders' && ! empty($tab['path'])) {
                $path = $tab['path'];
            }
        }
        $path ??= '/dashboard';

        return $path.(str_contains($path, '?') ? '&' : '?').'order='.$orderId;
    }

    /** Cache-buster for the PWA's own files under assets/owner-ui/ (file mtime). */
    public static function assetVersion(string $path): string
    {
        $file = public_path('assets/owner-ui/'.ltrim($path, '/'));

        return is_file($file) ? (string) filemtime($file) : self::SW_VERSION;
    }

    /** Where the surface came from: the native app, the installed PWA, or a browser tab. */
    public static function surface(Request $request): string
    {
        if (OwnerApp::inApp($request)) {
            return 'app';
        }
        if ($request->headers->get('X-Owner-PWA') === '1'
            || $request->query('source') === 'pwa'
            || $request->cookie(self::STANDALONE_COOKIE) === '1') {
            return 'pwa';
        }

        return 'browser';
    }

    /** Dependency-free device class from the user agent. */
    public static function device(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);
        if ($ua === '') {
            return 'desktop';
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')
            || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipod') || str_contains($ua, 'mobile')
            || str_contains($ua, 'android')) {
            return 'phone';
        }

        return 'desktop';
    }

    /** Brand theme color (reseller brand on a reseller host, else platform setting). */
    public static function themeColor(): string
    {
        $reseller = active_brand_reseller();
        $color = ($reseller && $reseller->getAttribute('primary_color')) ? $reseller->getAttribute('primary_color') : config('settings.theme_color');

        return HexColor::safe($color); // BL-036 R1
    }

    /**
     * Web app manifest for the current brand. Shortcuts follow the owner's
     * current store (same paths as the native tabs) when one is known.
     *
     * @return array<string, mixed>
     */
    public static function manifest(?Store $store = null): array
    {
        $name = brand_title();
        $color = self::themeColor();
        $locale = app()->getLocale();
        $dir = function_exists('current_language') && current_language() ? (current_language()->direction ?? 'ltr') : 'ltr';

        $icons = [];
        foreach (self::ICON_SIZES as $size) {
            $icons[] = ['src' => route('owner.pwa.icon', ['size' => $size]), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
        }
        $icons[] = ['src' => route('owner.pwa.icon', ['size' => 512, 'maskable' => 1]), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];

        $shortcuts = [];
        if ($store) {
            foreach (OwnerApp::tabsFor($store) as $tab) {
                if (! empty($tab['path']) && in_array($tab['key'], ['orders', 'catalog', 'reports'], true)) {
                    $shortcuts[] = ['name' => $tab['label'], 'url' => $tab['path']];
                }
            }
        }

        return array_filter([
            'id' => '/dashboard?source=pwa',
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => '/dashboard?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            // Splash + title bar follow the redesign's light surfaces; the brand colour
            // lives in the icon. The layout's media-query theme-color metas (and
            // owner-ui/pwa.js) switch the bar to the dark surface in dark mode.
            'background_color' => config('owner_ui.skin') ? self::BACKGROUND : '#ffffff',
            'theme_color' => config('owner_ui.skin') ? self::CHROME_LIGHT : $color,
            'lang' => $locale,
            'dir' => $dir,
            'icons' => $icons,
            'shortcuts' => $shortcuts ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Absolute local path of the brand favicon/logo to build icons from, or null.
     * Uses the same helpers the dashboard <head> uses (brand_favicon() returns a
     * URL under /storage/logo/…, brand_logo() may return a bare file name that
     * lives in storage/logo/). Remote (other-host) images are never fetched.
     */
    public static function iconSource(): ?string
    {
        $candidates = [];
        foreach ([brand_favicon(), brand_logo()] as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $ref)) {
                $host = parse_url($ref, PHP_URL_HOST);
                if ($host !== null && $host !== request()->getHost() && $host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
                    continue;
                }
                $ref = (string) parse_url($ref, PHP_URL_PATH);
            }
            $ref = ltrim($ref, '/');
            $candidates[] = public_path($ref);
            if (! str_contains($ref, '/')) {
                $candidates[] = public_path('storage/logo/'.$ref);
                $candidates[] = public_path('storage/'.$ref);
            }
        }
        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Square PNG icon: brand color background, logo centered (60% for "any",
     * 50% for "maskable" so it survives the safe-zone crop). Falls back to the
     * brand initial when no logo is readable. Returns PNG bytes.
     */
    public static function renderIcon(int $size, bool $maskable): string
    {
        $size = in_array($size, self::ICON_SIZES, true) ? $size : 192;
        $img = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = sscanf(self::themeColor(), '#%02x%02x%02x') ?: [99, 102, 241];
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

        $src = self::iconSource();
        $logo = $src ? @imagecreatefromstring((string) file_get_contents($src)) : false;
        if ($logo !== false) {
            // Logo on white: most brand logos/favicons are drawn for light backgrounds.
            // "any" fills 86%; "maskable" keeps it inside the 80% safe zone (66%).
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            $box = (int) round($size * ($maskable ? 0.66 : 0.86));
            $w = imagesx($logo);
            $h = imagesy($logo);
            $scale = min($box / max(1, $w), $box / max(1, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            imagealphablending($img, true);
            imagecopyresampled($img, $logo, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            imagedestroy($logo);
        } else {
            $white = imagecolorallocate($img, 255, 255, 255);
            $letter = strtoupper(mb_substr(brand_title() ?: 'M', 0, 1)) ?: 'M';
            $font = 5;
            $scale = (int) max(1, round($size / 40));
            $tmp = imagecreatetruecolor(imagefontwidth($font), imagefontheight($font));
            imagefill($tmp, 0, 0, imagecolorallocate($tmp, $r, $g, $b));
            imagestring($tmp, $font, 0, 0, $letter, imagecolorallocate($tmp, 255, 255, 255));
            $tw = imagefontwidth($font) * $scale;
            $th = imagefontheight($font) * $scale;
            imagecopyresized($img, $tmp, (int) (($size - $tw) / 2), (int) (($size - $th) / 2), 0, 0, $tw, $th, imagefontwidth($font), imagefontheight($font));
            imagedestroy($tmp);
            unset($white);
        }

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
