<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

/**
 * P6-132: the vendor API docs page (/docs/api, dedoc/scramble with the Stoplight Elements web component; admin/owner
 * gated by RestrictedDocsAccess). Enforced, applied through AddCspHeaders with this class as the argument.
 *
 * - script-src: 'self' (the Scramble dev-tools module, when on), the nonce (the fetch wrapper and the spec bootstrap in
 *   resources/views/vendor/scramble/docs.blade.php) and exactly the pinned Stoplight Elements package path on unpkg.
 * - style-src: the page's own <style> by nonce plus the same pinned stylesheet. Elements sets element styles from JS,
 *   which style-src-attr allows; <style> elements stay nonce-only.
 *   The bundle also injects the icon-font's fixed <style> at runtime; that one sheet is allowed by its hash
 *   (ICON_FONT_STYLE), which changes only with the pinned version.
 * - connect-src: 'self' only — the "Try it" console calls api/v1 on this origin (scramble api_domain is null).
 */
class ApiDocsPolicy extends Policy
{
    /** The only third-party origin + path the page may load code or styles from (matches the vendor view's pin). */
    public const ELEMENTS = 'https://unpkg.com/@stoplight/elements@8.4.2/';

    /** sha256 of the icon-font <style> that ELEMENTS injects (measured by tests/Browser/CspApiDocsTest.php). */
    public const ICON_FONT_STYLE = 'sha256-MBVp6JYxbC/wICelYC6eULCRpgi9kGezXXSaq/TS2+I=';

    public function configure(): void
    {
        $this
            ->addDirective(Directive::DEFAULT, Keyword::NONE)
            ->addDirective(Directive::BASE, Keyword::NONE)
            ->addDirective(Directive::OBJECT, Keyword::NONE)
            ->addDirective(Directive::FORM_ACTION, Keyword::SELF)
            ->addDirective(Directive::FRAME_ANCESTORS, Keyword::SELF)
            ->addDirective(Directive::SCRIPT, [Keyword::SELF, self::ELEMENTS])
            ->addDirective(Directive::SCRIPT_ATTR, Keyword::NONE)
            ->addDirective(Directive::STYLE, [Keyword::SELF, self::ELEMENTS, self::ICON_FONT_STYLE])
            ->addDirective(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE)
            ->addDirective(Directive::CONNECT, Keyword::SELF)
            ->addDirective(Directive::IMG, [Keyword::SELF, Scheme::DATA])
            ->addDirective(Directive::FONT, [Keyword::SELF, Scheme::DATA])
            ->addNonceForDirective(Directive::SCRIPT)
            ->addNonceForDirective(Directive::STYLE);
    }
}
