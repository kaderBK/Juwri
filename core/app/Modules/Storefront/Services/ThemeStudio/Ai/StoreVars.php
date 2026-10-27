<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use App\Models\MenuBanner;
use App\Services\Entitlements;
use App\Services\StorefrontToggleMask;
use App\Storefront\StorefrontExtras;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;

/**
 * Theme Studio phase D: the render-site variables (ThemeContext::build shape)
 * the AI theme builder reads its brief from and render-checks proposals
 * against — the REAL store's data for an owner, a DemoStorefront dataset for
 * superadmin generation and the eval.
 *
 * For a real store this is a read-only, simplified mirror of what
 * StoreController@publicView assembles (active storefront-visible items with
 * their variants/extras, banners, branches, menu languages, ordering flags,
 * StorefrontExtras). It never writes anything and is never used for a public
 * render — the owner's full-page preview goes through publicView itself.
 */
class StoreVars
{
    /** @return array<string,mixed> */
    public static function forStore(Store $store): array
    {
        $options = StorefrontToggleMask::apply(store_options($store->id), (int) $store->id);

        $codes = $options->menu_languages ?? [];
        if (is_string($codes)) {
            $decoded = json_decode($codes, true);
            $codes = is_array($decoded) ? $decoded : [$codes];
        }
        $default = (string) ($options->default_language ?? config('app.locale', 'en'));
        $codes = array_values(array_unique(array_filter(array_map('strval', array_merge([$default], is_array($codes) ? $codes : [])))));

        /** @var Collection<int,Language> $languages */
        $languages = Language::query()->whereIn('code', $codes)->where('active', 1)->get();
        if ($languages->isEmpty()) {
            $languages = collect([self::language($default)]);
        }
        $defaultLanguage = $languages->firstWhere('code', $default) ?? $languages->first();

        $allowOrder = false;
        $flags = ['allow_order' => false, 'on_table' => false, 'takeaway' => false, 'delivery' => false,
            'room_service' => false, 'payment' => false, 'scheduling' => ! empty($options->allow_order_scheduling)];
        try {
            if ($store->user && Entitlements::has($store->user, 'allow_ordering')) {
                $flags['on_table'] = (bool) ($options->restaurant_on_table_order ?? false);
                $flags['takeaway'] = (bool) ($options->restaurant_takeaway_order ?? false);
                $flags['delivery'] = (bool) ($options->restaurant_delivery_order ?? false);
                $flags['payment'] = config('settings.admin_allow_online_payment') ? (bool) ($options->restaurant_online_payment ?? false) : false;
                $allowOrder = $flags['on_table'] || $flags['takeaway'] || $flags['delivery'];
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $flags['allow_order'] = $allowOrder;

        $menus = [];
        try {
            $rows = Menu::with([
                'category',
                'variantOptions' => fn ($q) => $q->where('active', 1),
                'variants' => fn ($q) => $q->where('active', 1),
                'extras' => fn ($q) => $q->where('active', 1),
            ])
                ->visibleOnStorefront()
                ->where('store_id', $store->id)
                ->where('active', '1')
                ->orderBy('position')
                ->limit(500)
                ->get();
            foreach ($rows as $menu) {
                $menu->setRelation('globalGroups', collect());
                $menus[(int) $menu->id] = $menu;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $vars = [
            'store' => $store,
            'postOptions' => $options,
            'total_menus' => $menus,
            'allergies' => collect(),
            'menuBanners' => MenuBanner::where('store_id', $store->id)->where('active', true)->orderBy('position')->orderBy('id')->get(),
            'branches' => Branch::where('store_id', $store->id)->where('active', true)->orderBy('position')->get(),
            'activeBranch' => null,
            'table' => null,
            'customer' => null,
            'menu_languages' => $languages->values(),
            'default_menu_language' => $defaultLanguage,
            'scans_today' => 0,
            'flags' => $flags,
        ];

        try {
            $vars['extras'] = StorefrontExtras::for($store, $options, $flags);
        } catch (\Throwable $e) {
            report($e);
            $vars['extras'] = ['hours' => [], 'stories' => [], 'social' => [], 'announcement' => null, 'features' => []];
        }

        return $vars;
    }

    /**
     * A demo dataset for a store type (superadmin + eval), with the store type
     * stamped on the demo post and the default language chosen.
     *
     * @return array<string,mixed>
     */
    public static function forDemo(string $storeType, string $defaultLanguage = 'en'): array
    {
        $dataset = Recipes::DEMO_DATASET[$storeType] ?? 'restaurant';
        $vars = DemoStorefront::vars($dataset);
        $vars['store']->store_type = $storeType;

        $language = $vars['menu_languages']->firstWhere('code', $defaultLanguage);
        if ($language) {
            $vars['default_menu_language'] = $language;
        }

        return $vars;
    }

    /** An unsaved Language row for a code the languages table lacks. */
    public static function language(string $code): Language
    {
        $rtl = in_array(strtolower(substr($code, 0, 2)), ['ar', 'he', 'fa', 'ur'], true);

        return new Language(['code' => $code, 'name' => strtoupper($code), 'direction' => $rtl ? 'rtl' : 'ltr']);
    }
}
