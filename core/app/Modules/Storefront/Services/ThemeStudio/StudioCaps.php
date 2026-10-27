<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio;

use App\Services\FeatureAccess;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * Theme Studio C1: what an owner may change in the visual editor, per store.
 * Same idea as LockedTemplates\Permissions — the editor hides controls as a
 * convenience, LayoutMerge enforces every cap server-side and reports each
 * refused change in `rejected[]` (never a silent drop).
 *
 * Defaults come from plan entitlements (FeatureAccess, subject = the STORE
 * OWNER, kill switch included):
 *  - the structural + styling caps follow `theme_studio`;
 *  - `custom_block` follows `custom_sections` (phase A);
 *  - `ai_generate` follows `ai_theme_builder` (phase D).
 *
 * Reseller override hook: a reseller may RESTRICT (never widen) the caps of its
 * sub-stores through `resellers.settings.theme_studio_caps`, a nullable map of
 * cap => bool. A cap set to false there is off for every store whose owner
 * belongs to that reseller; a missing key / missing map changes nothing.
 */
class StudioCaps
{
    public const CAPS = [
        'sections_add',
        'sections_remove',
        'sections_reorder',
        'blocks_edit',
        'tokens',
        'fonts',
        'custom_block',
        'ai_generate',
    ];

    /** Reseller settings key holding the optional restriction map. */
    public const RESELLER_SETTING = 'theme_studio_caps';

    /** @return array<string,bool> every cap, strictly boolean */
    public static function forStore(Store $store): array
    {
        $studio = FeatureAccess::storeAllows((int) $store->id, 'theme_studio');

        $caps = [
            'sections_add' => $studio,
            'sections_remove' => $studio,
            'sections_reorder' => $studio,
            'blocks_edit' => $studio,
            'tokens' => $studio,
            'fonts' => $studio,
            'custom_block' => $studio && FeatureAccess::storeAllows((int) $store->id, 'custom_sections'),
            'ai_generate' => $studio && FeatureAccess::storeAllows((int) $store->id, 'ai_theme_builder'),
        ];

        return self::restrict($caps, self::resellerOverride($store));
    }

    /**
     * AND a restriction map onto caps: only an explicit `false` turns a cap off.
     *
     * @param  array<string,bool>  $caps
     * @param  array<string,mixed>|null  $override
     * @return array<string,bool>
     */
    public static function restrict(array $caps, ?array $override): array
    {
        $out = [];
        foreach (self::CAPS as $cap) {
            $allowed = (bool) ($caps[$cap] ?? false);
            if (is_array($override) && array_key_exists($cap, $override) && ! filter_var($override[$cap], FILTER_VALIDATE_BOOL)) {
                $allowed = false;
            }
            $out[$cap] = $allowed;
        }

        return $out;
    }

    /**
     * The owner's reseller restriction map, or null (no reseller / none set).
     *
     * @return array<string,mixed>|null
     */
    public static function resellerOverride(Store $store): ?array
    {
        try {
            $resellerId = (int) User::where('id', (int) $store->user_id)->value('reseller_id');
            $reseller = $resellerId > 0 ? app(ResellerContext::class)->find($resellerId) : null;
            $map = $reseller?->getAttribute('settings')[self::RESELLER_SETTING] ?? null;

            return is_array($map) ? $map : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
