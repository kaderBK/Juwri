<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services;

use App\Models\StoreOption;
use App\Services\Entitlements;
use App\Services\StorefrontThemeService;
use App\Services\Theme\VerticalThemeSets;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;

/**
 * Store vertical CONVERT — changes an existing store's `store_type` in place.
 *
 * SAME-GROUP convert (e.g. retail <-> pharmacy, both `goods`) is low risk: the
 * theme set and item-form stay valid, so it is a plain swap. CROSS-GROUP convert
 * (food <-> goods <-> services) needs a template that belongs to the TARGET
 * vertical's theme set — the old theme would otherwise keep rendering food/retail
 * chrome for a store that no longer is one — plus a list of what gets stranded
 * (old `orders.type` literals on past orders, food-only attributes, goods-only
 * variant/fitment/OTC rows) so the owner can make an informed call.
 *
 * Hotel (`stays`) has no menu<->room bridge yet, so it is excluded both as
 * source and target (v1). Converting TO any non-default (non-restaurant)
 * vertical mirrors StoreController::store's `multi_vertical` gate; converting
 * BACK to restaurant is free, exactly like creating a restaurant is free.
 *
 * The DUPLICATE-as-a-different-vertical flow (StoreVerticalController) shares
 * this class's guard (guardTargetType()) before calling StoreCloneService, so
 * the entitlement/hotel rules live in exactly one place for both flows.
 */
class StoreVerticalConversionService
{
    public function __construct(
        private VerticalRegistry $registry,
        private VerticalThemeSets $themeSets,
        private StorefrontThemeService $templates,
        private FulfillmentSettingsService $fulfillment,
    ) {}

    /**
     * Convert $store to $targetType, swapping the template when the vertical's
     * theme set changes. Idempotent: converting to the store's current type is
     * a no-op success. Wrapped in one DB transaction; owner/ownership checks are
     * the caller's responsibility (StoreVerticalController).
     *
     * @return array{error:?string, store:?Store, warnings:list<string>, sameGroup:bool}
     */
    public function convert(Store $store, User $actor, string $targetType, ?string $targetTemplate = null): array
    {
        if ($error = $this->guardTargetType($store, $actor, $targetType)) {
            return ['error' => $error, 'store' => null, 'warnings' => [], 'sameGroup' => false];
        }

        $sourceType = $store->store_type ?: $this->registry->default();

        // Idempotent no-op — nothing to swap, nothing to re-heal.
        if ($sourceType === $targetType) {
            return ['error' => null, 'store' => $store, 'warnings' => [], 'sameGroup' => true];
        }

        $sourceGroup = (string) $this->registry->capability($sourceType, 'group');
        $targetGroup = (string) $this->registry->capability($targetType, 'group');
        $sameGroup = $sourceGroup === $targetGroup;

        $resolvedTemplate = null;
        $warnings = [];

        if (! $sameGroup) {
            // CROSS-GROUP: a template valid for the TARGET theme set is REQUIRED —
            // the store's current template belongs to the source vertical's set and
            // would keep rendering that vertical's chrome otherwise.
            $validForTarget = $this->themeSets->filterFor($this->templates->available(), $targetType);
            if ($targetTemplate === null || $targetTemplate === '' || ! array_key_exists($targetTemplate, $validForTarget)) {
                return ['error' => ___('Choose a theme for the new store type before converting.'), 'store' => null, 'warnings' => [], 'sameGroup' => false];
            }
            $resolvedTemplate = $targetTemplate;
            $warnings = $this->strandedWarnings($sourceGroup);
        }

        DB::transaction(function () use ($store, $targetType, $resolvedTemplate) {
            $store->store_type = $targetType;
            $store->save();

            if ($resolvedTemplate !== null) {
                StoreOption::updateStoreOption($store->id, 'store_template', $resolvedTemplate);
            }

            // Fulfillment self-heal: enabledModes() already intersects the
            // persisted enabled-modes option against availableModes($store),
            // which reads the store_type just saved above — so this re-read is
            // already the healed subset. Writing it back (instead of leaving the
            // healing to happen only at read time) drops any mode the new
            // vertical does not allow from storage, not just from display.
            $this->fulfillment->update($store, $this->fulfillment->enabledModes($store));
        });

        log_activity('store.convert', $store, ['from' => $sourceType, 'to' => $targetType], $store->id);

        return ['error' => null, 'store' => $store->refresh(), 'warnings' => $warnings, 'sameGroup' => $sameGroup];
    }

    /**
     * Shared guard for BOTH convert and duplicate-as-a-different-vertical:
     * unknown target, hotel exclusion (either direction), and the
     * `multi_vertical` entitlement for any non-default target. Returns a
     * translated error message, or null when the target is allowed.
     */
    public function guardTargetType(Store $store, User $actor, string $targetType): ?string
    {
        if (! $this->registry->exists($targetType)) {
            return ___('Unknown store type.');
        }

        $sourceType = $store->store_type ?: $this->registry->default();

        // v1: no menu<->room bridge — hotel cannot be converted away from, nor
        // converted/duplicated into, in either direction.
        if ($sourceType === 'hotel' || $targetType === 'hotel') {
            return ___('Hotels cannot be converted to another type, and other stores cannot be converted to a hotel.');
        }

        // Mirrors StoreController::store ~208-214: any non-default (non-restaurant)
        // vertical is a paid feature; converting/duplicating back to restaurant
        // is always free.
        if ($targetType !== $this->registry->default() && ! Entitlements::allows($actor, 'multi_vertical')) {
            return ___('Non-restaurant stores are a premium feature. Please upgrade to create them.');
        }

        return null;
    }

    /**
     * Whether converting $store to $targetType would cross a vertical GROUP
     * (food|goods|services) — i.e. whether a template re-pick is required. Used
     * by the controller/view to decide whether to demand `store_template`.
     */
    public function requiresTemplateChoice(Store $store, string $targetType): bool
    {
        $sourceType = $store->store_type ?: $this->registry->default();

        return (string) $this->registry->capability($sourceType, 'group')
            !== (string) $this->registry->capability($targetType, 'group');
    }

    /**
     * What gets stranded by a CROSS-GROUP convert, translated. Existing orders
     * keep their historical `orders.type` literal no matter what (the column is
     * never rewritten); the rest depends on what the SOURCE group leaves behind.
     *
     * @return list<string>
     */
    private function strandedWarnings(string $sourceGroup): array
    {
        $warnings = [
            ___('Existing orders keep their original type and totals. Nothing already placed is affected.'),
        ];

        if ($sourceGroup === 'food') {
            $warnings[] = ___('Dish attributes such as vegetarian and non vegetarian will no longer be shown or editable.');
        }

        if ($sourceGroup === 'goods') {
            $warnings[] = ___('Product variants, vehicle fitment and pharmacy batch and expiry data will stay in the database but will no longer be shown or editable.');
        }

        return $warnings;
    }
}
