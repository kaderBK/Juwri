<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Render TRUSTED, admin-authored page HTML (pages.builder = 'trusted').
 *
 * Unlike Html::cleanPage() — which sanitises SEMI-trusted operator HTML down to an
 * allowlist — a trusted page is authored in the admin (superadmin) panel by the
 * fully-trusted platform operator, whose reach is already equivalent to editing a
 * Blade template. So the HTML (including <script>) is rendered AS-AUTHORED.
 *
 * The only transform is defence-in-depth for CSP: the request nonce is stamped onto
 * every inline <script>/<style> that lacks one, so the page also works verbatim under
 * a nonce-based Content-Security-Policy. We inject via a targeted regex (never a
 * DOMDocument round-trip) so complex inline JS is never re-encoded or reshaped.
 *
 * SAFETY BOUNDARY: this method performs NO sanitisation. It must ONLY ever be handed
 * content whose `builder` mode is 'trusted', which only an admin can set. Never call
 * it on reseller/biolink/store-owner content.
 */
class TrustedHtml
{
    public static function render(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $nonce = '';
        try {
            $nonce = app()->bound('csp-nonce') ? (string) app('csp-nonce') : '';
        } catch (\Throwable) {
            $nonce = '';
        }
        if ($nonce === '') {
            return $html;    // no CSP context (e.g. CLI) → return as-authored
        }

        $attr = ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"';

        // Add the nonce to each <script …>/<style …> opening tag that hasn't got one.
        return (string) preg_replace_callback(
            '/<(script|style)((?:\s[^>]*)?)>/i',
            function (array $m) use ($attr) {
                if (preg_match('/\snonce\s*=/i', $m[2])) {
                    return $m[0];
                }

                return '<'.$m[1].$attr.$m[2].'>';
            },
            $html
        );
    }
}
