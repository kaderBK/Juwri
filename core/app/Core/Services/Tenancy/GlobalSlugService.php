<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Cviebrock\EloquentSluggable\Services\SlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;

/**
 * BL-043: slugs are unique across EVERY account, not just the current tenant's rows. Inside an owner request the
 * tenant scope (and PostgreSQL RLS) would hide other accounts' rows from the package's "similar slugs" query, so a
 * second account could get the same store slug (/slug, QR codes and links would reach the wrong store, and a
 * first-store owner sees no rows at all). The existing-slug lookup therefore runs under TenantContext::bypass(),
 * which lifts both the app scope and the RLS session setting. It reads slug strings only.
 *
 * Bound for the Sluggable observer (CoreServiceProvider); call {@see self::createSlug()} on this class, not on the
 * package's SlugService, for tenant models.
 */
class GlobalSlugService extends SlugService
{
    /**
     * @param  array<string, mixed>  $config
     * @return Collection<int|string, mixed>
     */
    protected function getExistingSlugs(string $slug, string $attribute, array $config): Collection
    {
        return TenantContext::bypass(fn (): Collection => parent::getExistingSlugs($slug, $attribute, $config));
    }

    /**
     * BL-047: run a store write callback and retry with a freshly generated slug
     * when a concurrent insert/update hits the stores.slug unique constraint.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $write
     * @return TReturn
     */
    public static function writeWithUniqueSlug(string $title, callable $write, ?string $initialSlug = null, int $maxAttempts = 5): mixed
    {
        $slug = $initialSlug !== null && $initialSlug !== ''
            ? $initialSlug
            : static::createSlug(Store::class, 'slug', $title);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Savepoint keeps PostgreSQL transactions usable after a 23505 violation.
                if (DB::connection()->transactionLevel() > 0) {
                    return DB::transaction(fn () => $write($slug));
                }

                return $write($slug);
            } catch (UniqueConstraintViolationException $e) {
                if (! self::isStoreSlugCollision($e) || $attempt === $maxAttempts) {
                    throw $e;
                }

                $slug = static::createSlug(Store::class, 'slug', $title.'-'.strtolower(Str::random(4)));
            }
        }

        throw new \LogicException('Unreachable');
    }

    private static function isStoreSlugCollision(UniqueConstraintViolationException $e): bool
    {
        $msg = strtolower($e->getMessage());

        // Only the stores.slug unique index: MySQL/MariaDB/PostgreSQL name it, SQLite names the column.
        return str_contains($msg, 'stores_slug_unique')
            || str_contains($msg, 'unique constraint failed: stores.slug');
    }
}
