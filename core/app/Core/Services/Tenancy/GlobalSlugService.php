<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Cviebrock\EloquentSluggable\Services\SlugService;
use Illuminate\Support\Collection;

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
}
