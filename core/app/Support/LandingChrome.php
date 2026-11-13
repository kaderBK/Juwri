<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Juwri\Core\Models\Language;
use Illuminate\Support\Collection;
use Juwri\Core\Settings\PlatformSettings;

/**
 * Shared data for public pages that EXTEND the home landing layout
 * (templates/{theme}/layouts/landing) so they carry the exact site nav, footer,
 * fonts and theme — /mcp and the /developers docs.
 *
 * The home layout reads $activeTheme, $settings and $languages, normally shared
 * by the frontend view composer — which is SKIPPED when the app is not
 * "installed" (e.g. under the test runner). So any controller rendering a page
 * that extends the layout spreads self::data() into its view() call.
 *
 * Kept as a plain service (not a trait) on purpose: a trait's method docblocks
 * are resolved by PHPStan per-consuming-class and can be dropped for one of
 * them; a class resolves its own docblocks in its own context.
 */
class LandingChrome
{
    /**
     * The three layout variables, ready to spread: view('page', [...] + LandingChrome::data()).
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return [
            'activeTheme' => active_theme(),
            'settings' => self::brandAwareSettings(),
            'languages' => self::frontendLanguages(),
        ];
    }

    /**
     * Platform settings with the brand identity overridden to the current host's
     * brand (DEC-5), so the layout's nav/footer never leak the platform on a
     * reseller (white-label) domain. The brand name always; and because a reseller
     * has no social/contact fields of its own, the platform socials + contact
     * details are blanked (contact email maps to the reseller's support email) so
     * they can't leak into the footer or the JSON-LD sameAs either.
     */
    public static function brandAwareSettings(): object
    {
        // P1-07: credentials masked (they were passed through encrypted before).
        $opts = PlatformSettings::masked();
        // Brand-aware override: the layout's nav/footer read site_title.
        $opts['site_title'] = brand_title();

        // Same platform-identity scrub the live view composer applies, so the
        // test-runner path (composer skipped) matches production on a reseller host.
        return brand_scrub_settings(array_to_object($opts));
    }

    /**
     * Active frontend languages for the layout's language picker (defensive).
     *
     * @return Collection<int, Language>
     */
    public static function frontendLanguages(): Collection
    {
        try {
            return Language::where('active', 1)->orderBy('position', 'asc')->get();
        } catch (\Throwable $e) {
            return new Collection;
        }
    }
}
