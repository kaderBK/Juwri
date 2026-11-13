<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Marketplace;

use Juwri\Core\Editions\Community\NoDirectory;
use Juwri\Core\Models\Store;

/**
 * P1-13 extension point: the restaurant directory (Fusion marketplace directory, Marketplace module, ee). Community
 * has no directory ({@see NoDirectory}).
 */
interface Directory
{
    /** Is the directory module switched on (public pages, sitemap)? */
    public function enabled(): bool;

    /** Show the owner's "Directory" nav item for this store (module on + a listing or a marketplace opt-in)? */
    public function navVisible(int $storeId, ?Store $store): bool;

    /**
     * Directory sitemap entries (explore pages, unclaimed listings when indexed).
     *
     * @return list<array{loc: string, priority?: string}>
     */
    public function sitemapEntries(): array;
}
