<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Views;

use Illuminate\Support\Collection;
use Juwri\Core\Contracts\Google\GoogleMerchantId;
use Juwri\Core\Models\Branch;
use Juwri\Core\Support\ViewHelpers;

/**
 * Small per-request helpers extracted from owner and storefront Blade views to avoid
 * compiling closure literals (`fn (...)` / `function (...) {...}`) into those views.
 * Mirror of {@see ViewHelpers} but scoped to the ViewClosureAllowlist
 * views for the store / owner / post-templates surfaces; kept in its own namespace and
 * class so each consumer view depends on one symbol only.
 *
 * Behaviour MUST stay byte-identical with the closures they replace; any value-coercion,
 * null-handling, or per-key branch is preserved here.
 */
final class StoreViewHelpers
{
    /**
     * Translated per-vertical labels for the live-preview eyebrow and Alpine maps.
     * Used by owner/stores/create.blade.php.
     *
     * @param  iterable<mixed, array<string, mixed>>  $verticalTypes
     * @return array<string, string>
     */
    public static function verticalLabels(iterable $verticalTypes): array
    {
        $out = [];
        foreach ($verticalTypes as $key => $cfg) {
            $out[(string) $key] = ___($cfg['label'] ?? $key);
        }

        return $out;
    }

    /**
     * Build the [folder => name] map passed to the wizard Alpine component.
     * Used by owner/stores/create.blade.php.
     *
     * @param  iterable<int, array<string, mixed>>  $templates
     * @return array<string, string>
     */
    public static function templateNamesMap(iterable $templates): array
    {
        $out = [];
        foreach ($templates as $tpl) {
            $out[$tpl['folder']] = $tpl['name'];
        }

        return $out;
    }

    /**
     * Build the public asset URL for the up-to-3 preset thumbs of the chosen template.
     * Used by owner/stores/edit.blade.php.
     *
     * @param  iterable<string>  $paths
     * @return array<int, string>
     */
    public static function presetThumbUrls(iterable $paths, string $selectedTemplate): array
    {
        $out = [];
        foreach ($paths as $p) {
            $out[] = asset('assets/store_templates/'.$selectedTemplate.'/presets/'.basename($p));
        }

        return $out;
    }

    /**
     * Resolve the per-type template list (slug/name pairs) for the convert/duplicate
     * picker. Used by owner/stores/partials/store-type-convert.blade.php.
     *
     * @param  iterable<int, array<string, mixed>>  $availableTemplates
     * @return array<int, array{slug: string, name: string}>
     */
    public static function verticalTemplatePairs(object $themeSets, mixed $availableTemplates, mixed $type): array
    {
        $out = [];
        foreach ($themeSets->filterFor($availableTemplates, $type) as $slug => $tpl) {
            $out[] = ['slug' => $slug, 'name' => $tpl['name']];
        }

        return $out;
    }

    /**
     * Build the picker rows used by the convert+duplicate Alpine component.
     * Mirrors the original closure that walks the registry, applies the `hotel` skip,
     * resolves per-type templates and the `premium` (non-default vertical) flag.
     * Used by owner/stores/partials/store-type-convert.blade.php.
     *
     * @param  iterable<string, array<string, mixed>>  $registryAll
     * @param  iterable<int, array<string, mixed>>  $availableTemplates
     * @return array<int, array<string, mixed>>
     */
    public static function convertPickerTypes(iterable $registryAll, object $themeSets, iterable $availableTemplates, object $registry): array
    {
        $out = [];
        foreach ($registryAll as $type => $cfg) {
            if ($type === 'hotel') {
                continue;
            }
            $out[] = [
                'type' => $type,
                'label' => ___($cfg['label']),
                'group' => $cfg['group'],
                // Converting/duplicating TO any non-default (non-restaurant) vertical
                // needs the multi_vertical entitlement — mirrors
                // StoreVerticalConversionService::guardTargetType().
                'premium' => $type !== $registry->default(),
                'templates' => self::verticalTemplatePairs($themeSets, $availableTemplates, $type),
            ];
        }

        return $out;
    }

    /**
     * Map each eligible-branch entry to its integer id.
     * Used by owner/branches/index.blade.php.
     *
     * @param  iterable<int, object>  $eligible
     * @return array<int, int>
     */
    public static function eligibleBranchIds(iterable $eligible): array
    {
        $out = [];
        foreach ($eligible as $e) {
            $out[] = (int) $e->id;
        }

        return $out;
    }

    /**
     * Branch Google eligibility + formatted merchant id (or null when not eligible).
     * Used by owner/branches/index.blade.php.
     *
     * @param  array<int, int>  $eligibleBranchIds
     * @return array{eligible: bool, merchant_id: ?string}
     */
    public static function branchGoogleData(?object $b, array $eligibleBranchIds, bool $storeSingle, int $storeId): array
    {
        $eligible = $b !== null && in_array((int) $b->id, $eligibleBranchIds, true);

        return [
            'eligible' => $eligible,
            'merchant_id' => $eligible
                ? GoogleMerchantId::format((int) $storeId, (int) $b->id, $storeSingle)
                : null,
        ];
    }

    /**
     * Per-branch order-type override state as a Blade string ('' | '1' | '0').
     * Used by owner/branches/index.blade.php.
     */
    public static function otOverrideState(?object $b, string $t): string
    {
        if ($b === null) {
            return '';
        }
        $v = $b->orderTypeOverride($t);

        return $v === null ? '' : ($v ? '1' : '0');
    }

    /**
     * Asset URL with mtime cache-buster; empty when the file is missing.
     * Used by owner/design/studio.blade.php.
     */
    public static function tsAsset(string $rel): string
    {
        $mt = @filemtime(public_path($rel));

        return asset($rel).($mt ? '?v='.$mt : '');
    }

    /**
     * Recommended flag for a theme card (matches all / current group / current type).
     * Used by owner/design/studio.blade.php.
     *
     * @param  array<int, string>  $groups
     */
    public static function tsRecommended(array $groups, mixed $tsGroup, mixed $tsType): bool
    {
        return $groups === [] || in_array('all', $groups, true) || in_array($tsGroup, $groups, true) || in_array($tsType, $groups, true);
    }

    /**
     * Drop null / empty-string entries from the QR query array.
     * Used by owner/qr/builder.blade.php.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function arrayFilterNotEmpty(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Join each size-guide row into a CSV string.
     * Used by owner/retail/size-guides.blade.php.
     *
     * @param  iterable<int|string, mixed>  $rows
     * @return array<int, string>
     */
    public static function rowsToCsv(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = implode(', ', (array) $r);
        }

        return $out;
    }

    /**
     * Keep only those languages the URL-prefix localizer can resolve (or all when
     * session mode is enabled). Used by store_templates/_partials/account-extras.blade.php.
     *
     * @param  iterable<int, object>  $languages
     * @param  array<string, mixed>|null  $supported
     * @return array<int, object>
     */
    public static function supportedLanguages(iterable $languages, mixed $sessionMode, mixed $supported): array
    {
        $out = [];
        foreach ($languages as $l) {
            if ($sessionMode || (is_array($supported) && isset($supported[$l->code]))) {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * Keep custom blocks whose placement is in the configured PLACEMENTS list.
     * Used by store_templates/_partials/custom-blocks.blade.php.
     *
     * @param  iterable<int, object>  $blocks
     * @param  array<int, string>  $placements
     * @return array<int, object>
     */
    public static function customBlocksByPlacement(iterable $blocks, array $placements): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (in_array($b->placement, $placements, true)) {
                $out[] = $b;
            }
        }

        return $out;
    }

    /**
     * Per-channel sRGB-linearization used in the relative-luminance formula.
     * Used by store_templates/_partials/hotel-page-brand.blade.php (WCAG contrast pick).
     */
    public static function srgbLuminanceChannel(float $c): float
    {
        $c = $c / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    /**
     * Build the list of language cards shown by the locale-suggest strip, dropping the
     * current language. Used by store_templates/_partials/locale-suggest.blade.php.
     *
     * @param  iterable<int, object>  $menuLanguages
     * @return Collection<int, array{code: string, name: string, dir: string}>
     */
    public static function localeSuggestOffered(iterable $menuLanguages, string $currentLang): Collection
    {
        $current = strtolower($currentLang);
        $out = [];
        foreach ($menuLanguages as $l) {
            if (strtolower((string) $l->code) === $current) {
                continue;
            }
            $out[] = [
                'code' => strtolower((string) $l->code),
                'name' => (string) $l->name,
                'dir' => (string) ($l->direction ?? 'ltr'),
            ];
        }

        return new Collection($out);
    }

    /**
     * Filter the branch widget list down to genuine Branch model instances.
     * Used by store_templates/_partials/widgets/branch-switcher.blade.php.
     *
     * @param  iterable<int, mixed>  $branches
     * @return array<int, Branch>
     */
    public static function branchInstances(iterable $branches): array
    {
        $out = [];
        foreach ($branches as $b) {
            if ($b instanceof Branch) {
                $out[] = $b;
            }
        }

        return $out;
    }

    /**
     * Does a branch have non-empty numeric latitude / longitude values?
     * Used by store_templates/partials/store-map.blade.php.
     */
    public static function branchHasCoords(mixed $b): bool
    {
        if (! is_object($b) || ! isset($b->latitude, $b->longitude)) {
            return false;
        }

        return $b->latitude !== ''
            && $b->longitude !== ''
            && is_numeric($b->latitude) && is_numeric($b->longitude);
    }

    /**
     * Order status label for storefront themes (glow / matcha / profile). Fixed
     * literals so ___() is never handed a runtime value (which would auto-seed it
     * into every locale with the English text). Defaults to ucfirst of the input.
     */
    public static function orderStatusLabel(mixed $status): string
    {
        return match ((string) $status) {
            'unpaid' => ___('Awaiting Payment'),
            'new', 'pending' => ___('Received'),
            'preparing' => ___('Preparing'),
            'ready' => ___('Ready'),
            'completed' => ___('Completed'),
            'cancelled' => ___('Cancelled'),
            default => ucfirst((string) $status),
        };
    }
}
