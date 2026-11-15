<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P2 — asserts the storefront-hot `posts.slug` lookup key carries its index.
 */
class PostsSlugIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_slug_index_exists(): void
    {
        $this->assertTrue(
            Schema::hasIndex('stores', 'stores_slug_unique'),
            'Expected stores_slug_unique on stores(slug) — the storefront slug lookup would otherwise full-scan.'
        );
    }

    public function test_index_name_fits_mysql_cap_with_prefix(): void
    {
        // qrtest_ prefix (7) + name must stay under the MySQL 64-char identifier cap.
        $this->assertLessThanOrEqual(64, strlen('qrtest_'.'stores_slug_unique'));
    }
}
