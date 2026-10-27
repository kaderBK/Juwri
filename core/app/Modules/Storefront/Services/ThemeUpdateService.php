<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services;

use App\Models\StoreOption;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\ThemePurchaseService;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Services\ThemeStudio\StoreLayouts;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;

/**
 * P4-7: safe theme updates on the P2-6 immutable ledger. A restaurant PINS a
 * version (`mp:<slug>@<semver>` in store_template); sellers ship updates
 * as NEW published rows; the merchant chooses when to move. Settings live
 * under the versionless key (P2-3/P2-4), so switching versions never wipes
 * them — that split is the whole point of the OS 2.0 model.
 */
class ThemeUpdateService
{
    /**
     * Restaurants of this owner running an mp: theme with a NEWER published
     * version available.
     *
     * @return list<array{store_id:int, post_title:string, slug:string, current:string, latest:string}>
     */
    public function updatesAvailable(int $userId): array
    {
        $out = [];

        $templateRows = StoreSettings::valuesFor('store_template', Store::where('user_id', $userId)->pluck('id')->map(fn ($id) => (int) $id)->all());

        foreach ($templateRows as $storeId => $template) {
            if (! preg_match('/^mp:([a-z0-9\-]+)@([a-z0-9.\-]+)$/i', (string) $template, $m)) {
                continue;
            }
            [, $slug, $currentSemver] = $m;

            $theme = Theme::where('slug', strtolower($slug))->first();
            if (! $theme) {
                continue; // reference theme without a ledger — nothing to offer
            }
            $latest = $this->latestPublished($theme);
            $current = $theme->versions()->where('semver', $currentSemver)->first();

            // Newer = later ledger row (ids are monotonic; published rows immutable).
            if ($latest && (! $current || $latest->id > $current->id)) {
                $store = Store::find($storeId);
                $out[] = [
                    'store_id' => (int) $storeId,
                    'post_title' => $store->title ?? '',
                    'slug' => $theme->slug,
                    'current' => $currentSemver,
                    'latest' => $latest->semver,
                ];
            }
        }

        return $out;
    }

    /**
     * Move one restaurant to a published version of ITS current theme
     * (latest when $semver is null). Entitlement re-checked; settings under
     * the versionless key are untouched by design.
     *
     * @throws \RuntimeException on gate failures.
     */
    public function updateRestaurant(Store $store, ?string $semver = null): string
    {
        $raw = (string) (store_options($store->id)->store_template ?? '');
        if (! preg_match('/^mp:([a-z0-9\-]+)@/i', $raw, $m)) {
            throw new \RuntimeException('Restaurant is not running a marketplace theme.');
        }
        $theme = Theme::where('slug', strtolower($m[1]))->firstOrFail();

        if (! ThemePurchaseService::entitled((int) $store->user_id, $theme)) {
            throw new \RuntimeException('Account holds no license for this theme.');
        }

        $target = $semver
            ? $theme->versions()->where('semver', $semver)->where('status', 'published')->first()
            : $this->latestPublished($theme);
        if (! $target) {
            throw new \RuntimeException('No published version to update to.');
        }

        $template = 'mp:'.$theme->slug.'@'.$target->semver;
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        $this->migrateLayouts($store, $template);

        return $template;
    }

    /**
     * Theme Studio C5: layouts reference section TYPES, so they keep working
     * across versions — except where the new code removed a type (dropped from
     * the layout, with an owner notice) or renamed a setting (the section
     * schema's `migrations` map). Never fails the update itself.
     *
     * @return list<array<string,string>> notices added
     */
    public function migrateLayouts(Store $store, string $template): array
    {
        try {
            $theme = MarketplaceThemeResolver::resolve($template);

            return $theme && $theme->isSectioned() ? StoreLayouts::migrate($store, $theme) : [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    protected function latestPublished(Theme $theme): ?ThemeVersion
    {
        return $theme->versions()->where('status', 'published')->orderByDesc('id')->first();
    }
}
