<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

/**
 * M13 — the single source of truth for the seventeen retained ("modern") menu
 * templates.
 *
 * This list is not cosmetic. It decides, on the live storefront:
 *   · layout.blade.php        — skip jQuery/popper/bootstrap/windpage
 *   · _partials/home-order-type, offers-strip, item-reviews,
 *     schedule-slot-picker — whether the shared section renders at all
 *   · StoreController::publicView — whether Quick_user_lang_code is pinned to
 *     the URL locale (an unpinned cookie mixes AR content under EN chrome)
 *
 * Before this class the same literal lived in 20+ places under many names and
 * shapes — including all of the render decisions above AND every test that
 * would have caught them, so an 18th template could ship with four shared
 * sections missing and a fully green suite.
 *
 * Order is the canonical porting order: crave first (it is the reference theme
 * the others are ported from). Consumers that need a sorted or set comparison
 * must sort a copy; never reorder this constant.
 *
 * Retiring or adding a template is now ONE edit here plus whatever that
 * template actually needs. `KeptTemplateRegistryTest` fails if any consumer
 * grows a private copy again.
 */
final class KeptTemplates
{
    /** The seventeen retained modern templates. @var list<string> */
    public const MODERN = [
        'crave', 'matcha', 'glow', 'aurora', 'vivid', 'feast', 'velvet', 'dough',
        'plate', 'lime', 'picnic', 'orbit', 'velora', 'crumb', 'soiree', 'scoop', 'ember',
    ];

    /**
     * MODERN + `pageview`. pageview is first-party and ships a schema and a
     * layout of its own, so the tokens/font-pair contracts cover it — but it is
     * NOT a retained storefront template and gets none of the shared sections.
     *
     * @return list<string>
     */
    public static function withPageview(): array
    {
        return [...self::MODERN, 'pageview'];
    }

    /**
     * MODERN + the other first-party template dirs (`pageview`, `folio`).
     * Used by the "no keeper theme reads the legacy option" sweeps, which
     * police every first-party theme rather than only the retained ones.
     * `folio` (flip-book, images|PDF) is first-party but display-only, so —
     * like pageview — it is NOT retained/modern and gets none of the shared
     * storefront sections. (The legacy `flipbook` template was removed in
     * juwri Phase 0 WI-03; saved `flipbook` stores render on folio.)
     *
     * @return list<string>
     */
    public static function firstParty(): array
    {
        return [...self::MODERN, 'pageview', 'folio'];
    }

    /** Is this template slug one of the retained seventeen? */
    public static function isModern(?string $template): bool
    {
        return in_array((string) $template, self::MODERN, true);
    }
}
