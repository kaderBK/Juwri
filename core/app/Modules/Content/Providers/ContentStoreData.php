<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Content module's tables that name a user, for the store-data registry. The owner's blog comments,
 * docs events and locked-template permissions go with the account; blog posts are platform content (authors are staff).
 */
final class ContentStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::owned('blog_comments'),
            StoreTable::platform('blog_posts', 'platform blog content; the author is a platform user'),
            StoreTable::owned('docs_events'),
            StoreTable::owned('locked_template_permissions'),
        ];
    }
}
