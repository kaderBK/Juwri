<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services\GoogleWallet;

use App\Support\BioLinkOutbound;
use Juwri\Core\Models\Store;

/**
 * Shared branding helpers for the pass mappers: store name, hex colour, logo URI.
 * Kept small and defensive — every value has a safe fallback so a half-configured
 * store still produces a valid (if plain) pass.
 */
trait PassBranding
{
    /** Merchant name shown on the pass (store title, else platform site title). */
    protected static function issuerName(Store $store): string
    {
        $title = trim((string) ($store->title ?? ''));

        return $title !== '' ? $title : (string) config('settings.site_title', 'Store');
    }

    /**
     * A valid #RRGGBB colour for the pass background. Prefers the theme accent, then
     * the store's own `color`, then a neutral default. Non-hex values are dropped.
     */
    protected static function hexColor(Store $store): string
    {
        $candidates = [
            (string) theme_setting($store->id, 'colors.accent', ''),
            (string) ($store->color ?? ''),
        ];
        foreach ($candidates as $c) {
            $c = trim($c);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
                return strtoupper($c);
            }
            if (preg_match('/^#[0-9a-fA-F]{3}$/', $c)) {
                // expand shorthand #abc → #aabbcc
                return strtoupper('#'.$c[1].$c[1].$c[2].$c[2].$c[3].$c[3]);
            }
        }

        return '#1F6FEB';
    }

    /**
     * Absolute HTTPS logo URI, or null when the store has no real logo. Google
     * requires https for image URIs, so an http asset URL is upgraded defensively.
     */
    protected static function logoUri(Store $store): ?string
    {
        $file = (string) ($store->main_image ?? '');
        if ($file === '' || $file === 'default.png') {
            return null;
        }

        $url = media_url('storage/store/logo/', $file, (int) $store->id);
        if ($url === '') {
            return null;
        }

        return preg_replace('#^http://#', 'https://', $url);
    }

    /**
     * biolink-tpl lane-d (T3-1): a Google Wallet `linksModuleData` pointing at the
     * store's public bio page (?src=share), or null.
     *
     * Two gates, both regression guards: the store must have opted in via the
     * per-store `wallet_bio_link` StoreOption, AND the bio page must actually be
     * live. Either off ⇒ null ⇒ the pass class carries no links module, exactly as
     * before this run. Google requires https URIs; bio URLs are always absolute
     * https from the named route.
     *
     * @return array{uris: array<int, array{uri: string, description: string, id: string}>}|null
     */
    protected static function bioLinksModule(Store $store): ?array
    {
        if ((string) store_options($store->id, 'wallet_bio_link', '0') !== '1') {
            return null;
        }

        $uri = BioLinkOutbound::liveUrl($store, 'share');
        if ($uri === null) {
            return null;
        }

        return [
            'uris' => [[
                'uri' => $uri,
                'description' => ___('Visit our page'),
                'id' => 'bio',
            ]],
        ];
    }
}
