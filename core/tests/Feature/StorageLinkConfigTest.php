<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `php artisan storage:link` read public_path('storage') at config-load time, before AppServiceProvider moved the public
 * path to the repo root, so it tried to link inside the non-existent core/public and failed with
 * "symlink(): No such file or directory". The configured link must sit where the app really serves it.
 */
class StorageLinkConfigTest extends TestCase
{
    public function test_storage_link_location_matches_the_runtime_public_path(): void
    {
        $links = config('filesystems.links');

        $this->assertSame([str_replace('//', '/', public_path('storage'))], array_keys($links));   // public_path() keeps the trailing slash of base_path('../')
        $this->assertDirectoryExists(dirname((string) array_key_first($links)));
        $this->assertSame(storage_path('app/public'), reset($links));
    }
}
