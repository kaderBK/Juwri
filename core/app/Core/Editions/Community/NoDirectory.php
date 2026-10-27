<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Marketplace\Directory;
use Juwri\Core\Models\Store;

/** P1-13 Community default: no restaurant directory. */
final class NoDirectory implements Directory
{
    public function enabled(): bool
    {
        return false;
    }

    public function navVisible(int $storeId, ?Store $store): bool
    {
        return false;
    }

    public function sitemapEntries(): array
    {
        return [];
    }
}
