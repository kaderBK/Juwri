<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Services\StoreCloneService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\GlobalSlugService;
use Tests\TestCase;

class StoreSlugUniqueConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_slug_has_unique_index_that_rejects_duplicates(): void
    {
        $this->assertTrue(Schema::hasIndex('stores', 'stores_slug_unique'));

        Store::factory()->create(['slug' => 'shared-cafe']);

        $this->expectException(UniqueConstraintViolationException::class);

        Store::factory()->create(['slug' => 'shared-cafe']);
    }

    public function test_write_with_unique_slug_retries_when_concurrent_insert_collides(): void
    {
        $first = Store::factory()->create([
            'title' => 'Acme Cafe',
            'slug' => 'acme-cafe',
        ]);

        $attempts = 0;
        $second = GlobalSlugService::writeWithUniqueSlug(
            'Acme Cafe',
            function (string $slug) use (&$attempts): Store {
                $attempts++;

                return Store::factory()->create([
                    'title' => 'Acme Cafe',
                    'slug' => $slug,
                ]);
            },
            initialSlug: 'acme-cafe',
        );

        $this->assertSame(2, $attempts);
        $this->assertNotSame($first->slug, $second->slug);
        $this->assertStringStartsWith('acme-cafe-', $second->slug);
    }

    public function test_migration_deduplicates_existing_duplicate_slugs_before_indexing(): void
    {
        $first = Store::factory()->create(['slug' => 'dup-store-initial-1']);
        $second = Store::factory()->create(['slug' => 'dup-store-initial-2']);

        $migration = require base_path('app/Core/Database/Migrations/2026_11_14_100000_make_stores_slug_unique.php');
        $migration->down();

        $this->assertFalse(Schema::hasIndex('stores', 'stores_slug_unique'));

        DB::table('stores')->whereIn('id', [$first->id, $second->id])->update(['slug' => 'dup-store']);

        $migration->up();

        $this->assertTrue(Schema::hasIndex('stores', 'stores_slug_unique'));
        $this->assertSame('dup-store', DB::table('stores')->where('id', $first->id)->value('slug'));
        $this->assertSame("dup-store-{$second->id}", DB::table('stores')->where('id', $second->id)->value('slug'));
    }

    public function test_migration_keeps_already_unique_slugs_and_renames_only_later_duplicates(): void
    {
        [$migration] = $this->prepareWithoutIndex();

        foreach ([[9001, 'cafe'], [9002, 'cafe'], [9003, 'cafe-9002']] as [$id, $slug]) {
            Store::factory()->create(['id' => $id, 'slug' => 'tmp-'.$id]);
            DB::table('stores')->where('id', $id)->update(['slug' => $slug]);
        }

        $migration->up();

        $this->assertSame('cafe', DB::table('stores')->where('id', 9001)->value('slug'));
        $this->assertSame('cafe-9002', DB::table('stores')->where('id', 9003)->value('slug'), 'already-unique slug must not move');
        $this->assertSame('cafe-9002-2', DB::table('stores')->where('id', 9002)->value('slug'), 'new slug must not collide with an existing one');
    }

    public function test_migration_logs_every_rename_and_is_safe_to_rerun(): void
    {
        [$migration] = $this->prepareWithoutIndex();

        foreach ([9101, 9102] as $id) {
            Store::factory()->create(['id' => $id, 'slug' => 'tmp-'.$id]);
            DB::table('stores')->where('id', $id)->update(['slug' => 'twin']);
        }

        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $m, array $ctx): bool => $ctx === ['store_id' => 9102, 'old_slug' => 'twin', 'new_slug' => 'twin-9102']
        );
        $migration->up();

        // Re-run after a partial failure (index missing, some rows already renamed): nothing more changes.
        Schema::table('stores', fn ($t) => $t->dropUnique('stores_slug_unique'));
        $migration->up();

        $this->assertSame('twin', DB::table('stores')->where('id', 9101)->value('slug'));
        $this->assertSame('twin-9102', DB::table('stores')->where('id', 9102)->value('slug'));
        $this->assertTrue(Schema::hasIndex('stores', 'stores_slug_unique'));
    }

    public function test_migration_treats_accent_and_case_variants_like_the_database_collation(): void
    {
        [$migration] = $this->prepareWithoutIndex();

        foreach ([[9201, 'cafe'], [9202, 'café'], [9203, 'CAFE']] as [$id, $slug]) {
            Store::factory()->create(['id' => $id, 'slug' => 'tmp-'.$id]);
            DB::table('stores')->where('id', $id)->update(['slug' => $slug]);
        }

        $migration->up(); // must not fail half-way on the unique index, whatever the collation

        $this->assertTrue(Schema::hasIndex('stores', 'stores_slug_unique'));
        $this->assertSame('cafe', DB::table('stores')->where('id', 9201)->value('slug'));
        $insensitive = DB::connection()->getDriverName() === 'mysql' || DB::connection()->getDriverName() === 'mariadb';
        if ($insensitive) {
            $this->assertSame('café-9202', DB::table('stores')->where('id', 9202)->value('slug'));
            $this->assertSame('CAFE-9203', DB::table('stores')->where('id', 9203)->value('slug'));
        } else {
            $this->assertSame('café', DB::table('stores')->where('id', 9202)->value('slug'));
            $this->assertSame('CAFE', DB::table('stores')->where('id', 9203)->value('slug'));
        }
    }

    public function test_retry_ignores_unrelated_unique_violations(): void
    {
        $attempts = 0;
        $this->expectException(UniqueConstraintViolationException::class);

        try {
            GlobalSlugService::writeWithUniqueSlug('Whatever', function (string $slug) use (&$attempts): void {
                $attempts++;
                $first = User::factory()->create(['email' => 'dup-slug-test@example.test']);
                User::factory()->create(['email' => $first->email]); // duplicate email, not a slug collision
            });
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_store_clone_gets_a_unique_slug_through_the_shared_writer(): void
    {
        $source = Store::factory()->create(['title' => 'Clone Me', 'slug' => 'clone-me']);

        $clone = app(StoreCloneService::class)->clone($source)['clone'];

        $this->assertNotSame($source->slug, $clone->slug);
        $this->assertNotEmpty($clone->slug);
        $this->assertSame(1, Store::withoutGlobalScopes()->where('slug', $clone->slug)->count());
    }

    public function test_store_creation_and_settings_writers_use_the_shared_slug_writer(): void
    {
        foreach ([
            'app/Http/Controllers/User/StoreController.php',
            'app/Core/Services/Store/StoreSettingsService.php',
            'app/Services/StoreCloneService.php',
        ] as $file) {
            $this->assertStringContainsString('GlobalSlugService::writeWithUniqueSlug', file_get_contents(base_path($file)), $file);
        }
    }

    /** @return array{0: object} */
    private function prepareWithoutIndex(): array
    {
        $migration = require base_path('app/Core/Database/Migrations/2026_11_14_100000_make_stores_slug_unique.php');
        $migration->down();
        $this->assertFalse(Schema::hasIndex('stores', 'stores_slug_unique'));

        return [$migration];
    }
}
