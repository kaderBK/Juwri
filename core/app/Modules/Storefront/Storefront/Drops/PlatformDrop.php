<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * Theme Studio follow-ups: the `platform` global — whose brand the "Powered by"
 * line names and whether the store may drop it. Built by
 * App\Storefront\StorefrontExtras::platform() from the same sources the Blade
 * storefront uses (the reseller-aware brand context, and the plan feature
 * `hide_branding` via store_plan_allows()).
 */
class PlatformDrop extends Drop
{
    /** @param  array<string,mixed>  $data  built by App\Storefront\StorefrontExtras */
    public function __construct(protected array $data = []) {}

    /** The platform (or reseller white-label) brand name, e.g. "juwri". */
    public function brand_name(): string
    {
        return (string) ($this->data['brand_name'] ?? '');
    }

    /** The brand's home page (the reseller's own domain for a white-label store). */
    public function brand_url(): string
    {
        return (string) ($this->data['brand_url'] ?? '');
    }

    /**
     * The store's plan lets it remove the platform branding (plan feature
     * `hide_branding`, or a reseller tier with `remove_powered_by`). Library
     * sections hide the "Powered by" line when this is true.
     */
    public function hide_branding(): bool
    {
        return (bool) ($this->data['hide_branding'] ?? false);
    }
}
