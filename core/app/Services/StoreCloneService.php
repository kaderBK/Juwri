<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Services\Theme\VerticalThemeSets;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\GlobalSlugService;
use Juwri\Core\Services\Tenancy\StoreDataEngine;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\ModifierGroup;
use Juwri\Modules\Payments\Services\StorePaymentGateways;

/**
 * C10 — deep-clone (duplicate) a store under the same account. The controller keeps the auth/quota/response
 * concerns and delegates the whole transactional copy here.
 *
 * P1-21 (J29-8): copy mode of the store-data registry. This class copies the store row (new slug and public id;
 * platform grants cleared as the registry declares for `stores`) and its settings without the owner's connections
 * ({@see StoreDataRegistry::isOwnerSetting()}); {@see StoreDataEngine::duplicate()} copies every table a module
 * declares Copy — brands, the category tree, items, the variant matrix, modifier groups/modifiers/attachments,
 * combos, car-parts fitments, bio blocks, tables/rooms (fresh unique QR keys) and gateway rows without credentials —
 * with every reference remapped onto the copies (new bigint ids). Orders, customers, staff, secrets, domains,
 * integrations, logs and stats are never copied. Media is copied by reference (same file names).
 *
 * Duplicate-as-a-different-vertical: `$targetStoreType`/`$targetTemplate` let a
 * caller (StoreVerticalController) spin off the clone under a DIFFERENT vertical
 * than the source, entitlement/hotel-guarded by
 * StoreVerticalConversionService::guardTargetType() before this runs. The source
 * store is never mutated — only the replicated row and its copied options.
 * Omitted (both null, every existing caller), the clone keeps the source's vertical and template.
 */
class StoreCloneService
{
    /**
     * @return array{clone: Store, counts: array<string,int>}
     */
    public function clone(Store $source, ?string $targetStoreType = null, ?string $targetTemplate = null): array
    {
        // P1-07: the store's settings come from stores.settings, as legacy name/value rows.
        $options = collect(StoreSettings::legacyRows((int) $source->id))->map(fn (array $row) => (object) $row);

        // Duplicate-as-a-different-vertical: work out the clone's template BEFORE
        // the transaction (pure read, no writes). When a target vertical is given
        // and the SOURCE's saved template is not valid for the TARGET's theme set
        // (VerticalThemeSets), the clone would otherwise keep rendering the wrong
        // vertical's storefront chrome — so it needs a template swap. An explicit
        // `$targetTemplate` wins when it is itself valid for the target; otherwise
        // fall back to that vertical's own default (StorefrontThemeService).
        // Null = no override, i.e. byte-identical to the pre-existing behaviour.
        $templateOverride = null;
        if ($targetStoreType !== null) {
            $validForTarget = app(VerticalThemeSets::class)->filterFor(
                app(StorefrontThemeService::class)->available(),
                $targetStoreType
            );
            $sourceTemplate = (string) optional($options->firstWhere('option_name', 'store_template'))->option_value;

            if ($targetTemplate !== null && $targetTemplate !== '' && array_key_exists($targetTemplate, $validForTarget)) {
                $templateOverride = $targetTemplate;
            } elseif (! array_key_exists($sourceTemplate, $validForTarget)) {
                $templateOverride = app(StorefrontThemeService::class)->fallbackSlug($targetStoreType);
            }
        }

        $counts = [];
        $clone = DB::transaction(function () use ($source, $options, $targetStoreType, $templateOverride, &$counts) {
            // Clone the post (restaurant)
            $clone = $source->replicate();
            $clone->settings = null; // P1-07: rebuilt below from the filtered options (never the owner's connections)
            $clone->title = $source->title.' ('.___('Copy').')';
            // Duplicate-as-a-different-vertical: override AFTER replicate() so
            // every other column still carries over from the source verbatim.
            if ($targetStoreType !== null) {
                $clone->store_type = $targetStoreType;
            }
            // P1-21: what the registry declares for the store row itself — platform grants (verified badge,
            // custom-code permission, marketplace approval/feature/review) are not the owner's to copy.
            $declaration = StoreDataRegistry::get('stores');
            foreach ($declaration->clear ?? [] as $column) {
                $clone->setAttribute($column, null);
            }
            foreach ($declaration->values ?? [] as $column => $value) {
                $clone->setAttribute($column, $value);
            }
            // BL-047: slug via the shared writer (global uniqueness + retry on the unique index).
            GlobalSlugService::writeWithUniqueSlug((string) $clone->title, function (string $slug) use ($clone): void {
                $clone->slug = $slug;
                $clone->save();
            });

            // P1-21: every other table the registry copies — the catalogue (brands, category tree, items, variant
            // matrix, modifier groups + modifiers + item attachments, combos, fitments), bio blocks, tables/rooms
            // (fresh QR keys) and gateway rows without credentials — every reference remapped onto the copies.
            $copied = app(StoreDataEngine::class)->duplicate(
                $clone->getConnection(), (int) $source->id, (int) $clone->id, (int) $clone->account_id
            );
            $counts = [
                'categories' => $copied['categories'] ?? 0,
                'items' => $copied['items'] ?? 0,
                // The dialog's legacy counters: variant groups ("modifiers") and add-ons ("extras").
                'modifiers' => DB::table('modifier_groups')->where('store_id', $clone->id)->where('kind', ModifierGroup::KIND_VARIANT)->count(),
                'extras' => DB::table('modifiers')->where('store_id', $clone->id)->where('kind', ModifierGroup::KIND_ADDON)->count(),
                'tables' => $copied['store_tables'] ?? 0,
                'bio_blocks' => $copied['bio_blocks'] ?? 0,
                'brands' => $copied['brands'] ?? 0,
                'variants' => $copied['menu_variants'] ?? 0,
                'fitments' => $copied['vehicle_fitments'] ?? 0,
                'combos' => $copied['combos'] ?? 0,
            ];

            // Clone the store settings — one write. `store_template` is swapped to $templateOverride when the
            // duplicate targets a different vertical than the source's template supports (computed above).
            // WI-06f / P1-21: never the owner's connections (every setting a module declares an owner setting:
            // gateway keys and ids, legacy plaintext secrets, tracking ids, bridge tokens).
            $optionRows = $options->reject(fn ($opt) => StoreDataRegistry::isOwnerSetting((string) $opt->option_name))->values()->map(fn ($opt) => [
                'store_id' => $clone->id,
                'option_name' => $opt->option_name,
                'option_value' => ($templateOverride !== null && $opt->option_name === 'store_template')
                    ? $templateOverride
                    : $opt->option_value,
            ])->all();
            // Edge case: the source has no store_template row at all (never
            // explicitly saved) but the clone still needs a valid one for its
            // target vertical — seed it rather than leaving the clone template-less.
            if ($templateOverride !== null && ! $options->contains('option_name', 'store_template')) {
                $optionRows[] = ['store_id' => $clone->id, 'option_name' => 'store_template', 'option_value' => $templateOverride];
            }
            if (! empty($optionRows)) {
                StoreSettings::setMany((int) $clone->id, array_column($optionRows, 'option_value', 'option_name'));
            }
            StorePaymentGateways::forgetMemo();

            return $clone;
        });

        return ['clone' => $clone, 'counts' => $counts];
    }
}
