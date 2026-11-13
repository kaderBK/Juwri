<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\SitePages;

use App\Models\Site;
use App\Models\SitePage;

/**
 * HOME-PAGES HP-14: precedence decisions for the public render seams (mirrors
 * ResellerPages\PublicResolver). Published block-page → render; null → the
 * caller falls through to the legacy Blade view. Unpublish therefore acts as
 * the per-site kill switch back to the hardcoded home.
 */
class SitePublicResolver
{
    public static function homeFor(?Site $site): ?SitePage
    {
        if (! $site) {
            return null;
        }

        try {
            return SitePage::forSite($site->id)
                ->published()
                ->where('type', 'home')
                ->orderBy('id')
                ->first();
        } catch (\Throwable $e) {
            // site_pages table missing (pre-migration) — legacy home, never 500.
            return null;
        }
    }

    /** Slug page precedence (HP-15): published site page for this slug, else null → legacy Page CMS. */
    public static function pageFor(?Site $site, string $slug): ?SitePage
    {
        if (! $site || $slug === '') {
            return null;
        }

        try {
            return SitePage::forSite($site->id)
                ->published()
                ->where('type', 'page')
                ->where('slug', $slug)
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
