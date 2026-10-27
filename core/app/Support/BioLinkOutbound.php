<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use App\Services\FeatureAccess;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioClick;
use Juwri\Modules\Biolink\Services\BioLinkService;

/**
 * BIOLINK-TPL lane-d: single seam every external integration (QR, campaigns,
 * GBP, wallet passes, directory) uses to reach a store's public bio page.
 *
 * Two jobs, both regression guards:
 *  - isLive(): the bio page only counts as a target when the feature is on
 *    (kill-switch + plan gate via FeatureAccess::storeAllows) AND the owner
 *    flipped their own enable switch. A dark/dead/unpublished bio must leave
 *    every integration on TODAY's exact behavior — callers use liveUrl() and
 *    fall back to their current URL on null.
 *  - url(): the outbound bio URL with a source-attribution bucket (?src=…) so
 *    the bio page's insights can split traffic by origin (qr/campaign/gbp/
 *    wallet/directory → share/social/qr buckets per §T2/§T3).
 *
 * F0 SEAM: Lane A ships a domain-aware `bio_public_url($store,$src)` helper.
 * This worktree predates it, so url() builds `route('bio.public',…).'?src='`
 * behind a single `// TODO F0` — one swap location for the whole lane.
 */
class BioLinkOutbound
{
    /**
     * Is this store's public bio page actually live for guests right now?
     * (feature key on + kill-switch on + plan grants + owner enable switch).
     */
    public static function isLive(?Store $store): bool
    {
        if (! $store || ! $store->id) {
            return false;
        }

        if (! FeatureAccess::storeAllows((int) $store->id, 'biolink')) {
            return false;
        }

        $settings = app(BioLinkService::class)->resolveSettings((int) $store->id);

        return (bool) $settings['enabled'];
    }

    /**
     * Absolute public bio URL with a source-attribution bucket. Does NOT check
     * liveness — call isLive()/liveUrl() for the guarded form.
     */
    public static function url(Store $store, string $src): string
    {
        // F0-merge: Lane A's domain-aware helper — resolves the white-label
        // custom-domain origin when the store has one, else the platform
        // "{slug}/bio" route, and applies the ?src bucket itself.
        return bio_public_url($store, $src);
    }

    /**
     * The bio URL only when the page is live, else null so the caller keeps its
     * current (pre-bio) behavior unchanged. This is the regression guard every
     * integration point leans on.
     */
    public static function liveUrl(?Store $store, string $src): ?string
    {
        if (! $store || ! self::isLive($store)) {
            return null;
        }

        return self::url($store, $src);
    }

    /**
     * bio-t15 Lane D — SPEC §7 D3: UTM auto-tag a bio BLOCK's outbound URL (the
     * link a visitor taps to LEAVE the bio page — Instagram, a website, an
     * aggregator app, …). Appends `utm_source=bio&utm_medium=<source>&
     * utm_campaign=<slug>`, respects any query string the merchant already put
     * on the link, and never double-appends: a URL that already carries
     * `utm_source` is returned untouched (the merchant tagged it on purpose).
     * Skips `tel:`/`mailto:`/`sms:` (a UTM query string is meaningless there)
     * and `wa.me` deep links (the destination is WhatsApp itself, not a
     * trackable landing page, and some WhatsApp clients mangle extra query
     * params on a `wa.me` link).
     *
     * *** INVESTIGATION FINDING (logged in LEDGER "§ Contract changes") ***
     * The SPEC named THIS class as "the outbound seam" to confirm/use for D3.
     * Reading it (above) shows `url()`/`liveUrl()` build the INBOUND
     * direction — the URL a QR code / campaign / directory listing uses to
     * REACH the bio page (`?src=` attribution on the way IN), not the URL a
     * bio-page BLOCK uses to send a visitor back OUT to Instagram/a website/
     * an aggregator. This class is not actually where an outbound block href
     * is emitted; that lives in `resources/views/bio/_blocks/*.blade.php`
     * (Lane A, e.g. `link.blade.php`'s `href="{{ $url }}"`) and/or the public
     * page's click-beacon script (`layout.blade.php`, Lane B) — both outside
     * Lane D's fence. This helper is the pure, tested UTM-tagging logic,
     * staying inside Lane D's owned file per the run's fallback instruction
     * ("put UTM where the outbound URL is emitted, staying within
     * analytics-owned files"); F0 calls `BioLinkOutbound::withUtm(...)` from
     * whichever partial/script actually renders the final href at
     * integration.
     */
    public static function withUtm(string $url, string $source, string $campaignSlug): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return $url; // no real destination (a block's empty-link placeholder, per link.blade.php)
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['tel', 'mailto', 'sms'], true)) {
            return $url;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === 'wa.me' || str_ends_with($host, '.wa.me')) {
            return $url;
        }

        $parts = @parse_url($url);
        if (! is_array($parts)) {
            return $url; // unparsable — never mangle a URL we can't safely rebuild
        }

        parse_str($parts['query'] ?? '', $query);
        if (isset($query['utm_source'])) {
            return $url; // already tagged (by the merchant or a prior pass) — never double-append
        }

        $query['utm_source'] = 'bio';
        $query['utm_medium'] = in_array($source, BioClick::SOURCES, true) ? $source : 'other';
        $query['utm_campaign'] = $campaignSlug;

        $rebuilt = '';
        if (! empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'].'://';
        } elseif (! empty($parts['host'])) {
            $rebuilt .= '//';
        }
        if (! empty($parts['user'])) {
            $rebuilt .= $parts['user'].(! empty($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        }
        $rebuilt .= $parts['host'] ?? '';
        if (! empty($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= '?'.http_build_query($query);
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }
}
