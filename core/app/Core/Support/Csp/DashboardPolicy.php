<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

use App\Support\Csp\StorefrontPolicy;
use Juwri\Core\Broadcasting\LiveUpdates;

/**
 * S12: CSP for the PRIVILEGED surfaces — merchant dashboard, superadmin,
 * reseller portal and staff PWA. A stored XSS in any of the 92 `{!! !!}` sinks
 * on these pages is code execution against the highest-privilege sessions, yet
 * only the public storefront had a CSP.
 *
 * Nonce-based, modelled on {@see StorefrontPolicy}. It ships REPORT-ONLY
 * (see AddDashboardCspHeaders + config('csp.enforce_dashboard'), default off):
 * violations land at /csp-report so the owner can nonce the remaining inline
 * dashboard scripts and widen for any real embed before enforcing — the same
 * report-only-first path the storefront took (P9-2). Nothing breaks on merge.
 */
class DashboardPolicy extends Policy
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
            // 'unsafe-eval' is required because the dashboard runs on Alpine.js,
            // which evaluates every x-data / @click / x-show expression through
            // new Function() — without it the enforced policy kills all dashboard
            // interactivity (verified live). This is the one place DashboardPolicy is
            // weaker than the enforced StorefrontPolicy (whose vanilla-JS themes need
            // no eval). It is a deliberate, owner-approved trade-off: inline <script>
            // (nonce), inline event handlers (script-src-attr 'none' + the data-lm-*
            // conversion) and external script injection stay blocked; only eval of
            // same-origin, blade-authored Alpine expressions is permitted. Removing it
            // requires migrating the dashboard to Alpine's CSP build (@alpinejs/csp).
            ->addDirective(Directive::SCRIPT, [Keyword::SELF, Keyword::UNSAFE_EVAL])
            ->addDirective(Directive::STYLE, [Keyword::SELF, Scheme::HTTPS])
            // Mirror StorefrontPolicy's enforced posture for the two attribute
            // sub-directives: inline event-handler attributes (onclick=, onchange=…)
            // are executable and get NO free pass — they must be converted to
            // delegated listeners (script-src-attr 'none'); inline style="" attributes
            // cannot run script and are allowed (style-src-attr 'unsafe-inline'), the
            // same call the storefront made rather than rewrite hundreds of them.
            ->addDirective(Directive::SCRIPT_ATTR, Keyword::NONE)
            ->addDirective(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE)
            ->addNonceForDirective(Directive::SCRIPT)
            ->addNonceForDirective(Directive::STYLE);

        // The dashboard shell loads the platform's Google Analytics tag (partials/analytics) when the admin switched it
        // on: enforced, the policy must name its hosts or pageviews silently stop. Only then.
        if (StorefrontPolicy::platformAnalyticsOn()) {
            $this->addDirective(Directive::SCRIPT, ['https://www.googletagmanager.com', 'https://www.google-analytics.com']);
            foreach (['https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://*.google-analytics.com',
                'https://*.analytics.google.com', 'https://stats.g.doubleclick.net'] as $host) {
                $this->addDirective(Directive::CONNECT, $host);
            }
        }

        // The platform's optional widgets, each only while it is configured (enforced, the policy must name them):
        // the Tawk.to chat (partials/quick-addons, after analytics consent) and the Turnstile sign-up challenge.
        $tawk = config('settings.tawk_to');
        $tawkOn = is_object($tawk) ? ($tawk->status ?? null) : (is_array($tawk) ? ($tawk['status'] ?? null) : null);
        if (! empty($tawkOn) && $tawkOn != '0') {
            $this->addDirective(Directive::SCRIPT, ['https://embed.tawk.to', 'https://*.tawk.to'])
                ->addDirective(Directive::CONNECT, ['https://*.tawk.to', 'wss://*.tawk.to'])
                ->addDirective(Directive::FRAME, 'https://*.tawk.to');
        }
        if ((string) config('settings.abuse_turnstile_site_key', '') !== '') {
            $this->addDirective(Directive::SCRIPT, 'https://challenges.cloudflare.com')
                ->addDirective(Directive::FRAME, 'https://challenges.cloudflare.com');
        }

        // Live updates (Reverb): the browser opens one WebSocket to the configured endpoint. Only while switched on.
        $live = LiveUpdates::clientConfig();
        if ($live !== null) {
            $this->addDirective(Directive::CONNECT, ($live['scheme'] === 'https' ? 'wss' : 'ws').'://'.$live['host'].':'.$live['port']);
        }
    }
}
