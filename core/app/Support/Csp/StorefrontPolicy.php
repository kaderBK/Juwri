<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support\Csp;

use Juwri\Core\Models\Store;
use Juwri\Core\Support\Csp\Directive;
use Juwri\Core\Support\Csp\Keyword;
use Juwri\Core\Support\Csp\Policy;
use Juwri\Core\Support\Csp\Scheme;

/**
 * P0-4: CSP for the public storefront (post_template menus). Nonce-based, no
 * unsafe-inline — the strongest backstop for the custom-code sinks and any XSS
 * that slips past the sanitizer. Ships report-only first (see config/csp.php,
 * env CSP_ENFORCE) so violations can be collected before enforcing.
 *
 * Third-party embeds (payment SDKs, maps) that legitimately violate this are
 * widened here after the report-only pass, per host.
 *
 * P9-2 (2026-07-06): every inline <script>/<style> in resources/views/
 * store_templates now carries nonce="{{ csp_nonce() }}" (151 tags across 42
 * files), guarded by CspEnforceTest so it can't regress — the storefront is
 * enforce-READY. The env flip (CSP_ENFORCE=true), the per-theme browser pass
 * confirming JS still runs under enforce, and widening this allowlist for any
 * real 3rd-party embed found in the /csp-report window remain the owner's.
 * Current allowlist: 'self' everywhere; img/font/media also data:/https:.
 */
class StorefrontPolicy extends Policy
{
    public function configure(): void
    {
        $this
            ->addDirective(Directive::BASE, Keyword::SELF)
            ->addDirective(Directive::DEFAULT, Keyword::SELF)
            ->addDirective(Directive::CONNECT, Keyword::SELF)
            ->addDirective(Directive::FORM_ACTION, Keyword::SELF)
            ->addDirective(Directive::FRAME_ANCESTORS, Keyword::SELF)
            ->addDirective(Directive::OBJECT, Keyword::NONE)
            ->addDirective(Directive::IMG, [Keyword::SELF, Scheme::DATA, Scheme::HTTPS])
            ->addDirective(Directive::FONT, [Keyword::SELF, Scheme::DATA, Scheme::HTTPS])
            ->addDirective(Directive::MEDIA, [Keyword::SELF, Scheme::HTTPS])
            ->addDirective(Directive::SCRIPT, Keyword::SELF)
            ->addDirective(Directive::SCRIPT_ATTR, Keyword::NONE)
            ->addDirective(Directive::STYLE, [Keyword::SELF, Scheme::HTTPS])
            // The retained themes still use style attributes extensively for
            // dynamic colours, progress, positioning, and visibility. CSP 3
            // isolates that compatibility exception to attributes; <style>
            // elements remain nonce-only and script attributes remain blocked.
            ->addDirective(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE)
            ->addNonceForDirective(Directive::SCRIPT)
            ->addNonceForDirective(Directive::STYLE);

        // Abuse Guard P4: when Cloudflare Turnstile is configured, allow its widget
        // script + iframe so CSP-enforce doesn't silently break the challenge.
        if ((string) config('settings.abuse_turnstile_site_key', '') !== '') {
            $this->addDirective(Directive::SCRIPT, 'https://challenges.cloudflare.com')
                ->addDirective(Directive::FRAME, 'https://challenges.cloudflare.com');
        }

        // Google (google-suite Lane D): the GTM container's no-JS <noscript>
        // iframe (ns.html) and the keyless store-map embed (maps ?output=embed)
        // both frame Google hosts. FRAME-SRC ONLY — never script-src: the gtag /
        // GTM loader <script> already rides the existing report-only allowance
        // (enforce-readiness for it is a recorded follow-up), and CspEnforceTest /
        // CspTest pin `script-src 'self' 'nonce-` exactly, so a host there would
        // break the gate. These two hosts in frame-src are asserted safe (no test
        // checks frame-src) and are gated at the feature level (GTM id present /
        // store_map_embed toggle), so widening the frame allow-list is harmless.
        $this->addDirective(Directive::FRAME, 'https://www.googletagmanager.com')
            ->addDirective(Directive::FRAME, 'https://www.google.com');

        // Biolink pages (Lane C): art-directed presets pull their display/body faces
        // from Google Fonts. The stylesheet loads from fonts.googleapis.com (STYLE)
        // and the font files from fonts.gstatic.com (FONT). Both are already covered
        // by the Scheme::HTTPS allowance above; listing them explicitly documents the
        // dependency and keeps behaviour identical if that scheme is ever tightened.
        // STYLE + FONT only — never SCRIPT (would break the pinned `script-src 'self'
        // 'nonce-`) and never FRAME-SRC (the video block is self-hosted <video>, G6).
        $this->addDirective(Directive::STYLE, 'https://fonts.googleapis.com')
            ->addDirective(Directive::FONT, 'https://fonts.gstatic.com');

        // Builder.js html-mode pages (biolink runs under this enforcing policy) may
        // embed an <iframe> from an allow-listed host — the SAME list Html::cleanPage
        // enforces (video/audio/map players). FRAME-SRC only (never script-src): the
        // iframe is also force-sandboxed by the sanitiser. cleanPage is the real gate;
        // this just lets the framed player load once cleanPage has kept the iframe.
        foreach ([
            'https://www.youtube.com', 'https://www.youtube-nocookie.com', 'https://youtu.be',
            'https://player.vimeo.com', 'https://open.spotify.com', 'https://w.soundcloud.com',
        ] as $embedHost) {
            $this->addDirective(Directive::FRAME, $embedHost);
        }

        $this->allowAnalytics();
    }

    /**
     * A2 (upstream 855066828): the analytics a page really
     * loads — the platform's Google Analytics tag (admin setting) and the
     * store's own GA/GTM id or Meta pixel (structured integrations) — need
     * their hosts, or enforcing the policy silently stops pageviews. Added
     * ONLY when that analytics is switched on, so a store without it keeps
     * the bare `script-src 'self' 'nonce-…'`.
     */
    private function allowAnalytics(): void
    {
        [$google, $meta] = self::analyticsInUse();
        if ($google) {
            foreach (['https://www.googletagmanager.com', 'https://www.google-analytics.com'] as $host) {
                $this->addDirective(Directive::SCRIPT, $host);
            }
            foreach ([
                'https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://*.google-analytics.com',
                'https://*.analytics.google.com', 'https://stats.g.doubleclick.net', 'https://www.google.com',
            ] as $host) {
                $this->addDirective(Directive::CONNECT, $host);
            }
        }
        if ($meta) {
            $this->addDirective(Directive::SCRIPT, 'https://connect.facebook.net')
                ->addDirective(Directive::CONNECT, 'https://www.facebook.com')
                ->addDirective(Directive::CONNECT, 'https://connect.facebook.net');
        }
    }

    /** The platform's own Google Analytics tag (admin setting), which the dashboard shell loads too. */
    public static function platformAnalyticsOn(): bool
    {
        $ga = config('settings.google_analytics');

        return is_object($ga) ? (($ga->status ?? '0') != '0' && ! empty($ga->measurement_id))
            : (is_array($ga) && ($ga['status'] ?? '0') != '0' && ! empty($ga['measurement_id']));
    }

    /** @return array{0: bool, 1: bool} [Google analytics on, Meta pixel on] for this request */
    public static function analyticsInUse(): array
    {
        $platformGa = self::platformAnalyticsOn();

        $storeGa = $storePixel = false;
        $slug = request()->route('slug');
        if (is_string($slug) && $slug !== '') {
            $id = Store::where('slug', $slug)->value('id');
            if ($id) {
                $o = store_options($id);
                $storeGa = ! empty($o->integration_ga_id ?? null);
                $storePixel = ! empty($o->integration_meta_pixel_id ?? null);
            }
        }

        return [$platformGa || $storeGa, $storePixel];
    }
}
