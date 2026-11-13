<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * google-partner Lane A / A5 — map a store's menu to the GBP FoodMenus payload.
 *
 * Shape (v4 FoodMenus, developers.google.com/my-business/reference/rest/v4/FoodMenus):
 *   ['menus' => [ ['labels'=>[MenuLabel], 'sections'=>[FoodMenuSection...]] ]]
 *   FoodMenuSection: ['labels'=>[MenuLabel], 'items'=>[FoodMenuItem...]]  (>=1 item required)
 *   FoodMenuItem:    ['labels'=>[MenuLabel], 'attributes'=>['price'=>Money, 'dietaryRestriction'=>[...]]]
 *   MenuLabel:       ['displayName'(<=140), 'description'?(<=1000), 'languageCode']
 *   Money:           ['currencyCode', 'units'(int), 'nanos'(0..999999999)]
 *
 * Field caps enforced (A0 findings): displayName<=140, description<=1000, empty
 * sections/items dropped (a section MUST carry >=1 item). Localization: read via the
 * model name/description accessors on a ->get()->map collection (NEVER pluck — that
 * bypasses the accessor); in a queued job there is no Quick_user_lang_code cookie, so
 * the accessor returns the store's DEFAULT-language value, which is exactly what we push.
 */
class GbpMenuSyncService
{
    private const LABEL_MAX = 140;

    private const DESC_MAX = 1000;

    /** Google DietaryRestriction enum has no GLUTEN_FREE — only the four below are mappable. */
    // is_vegan → VEGAN · type 'veg' → VEGETARIAN · is_halal → HALAL

    /**
     * Build the FoodMenus body for a store. Returns ['menus'=>[]] when there is nothing
     * pushable (the job then skips / records an empty sync).
     *
     * @return array{menus: array<int, array<string,mixed>>}
     */
    public function build(Store $store): array
    {
        $currency = strtoupper($this->currency($store));
        $lang = $this->languageCode($store);

        $categories = MenuCategory::where('store_id', $store->id)
            ->where('active', '1')->whereNull('parent')
            ->with([
                'menus' => fn ($q) => $q->where('active', '1')->orderBy('position'),
                'subcategories.menus' => fn ($q) => $q->where('active', '1')->orderBy('position'),
            ])
            ->orderBy('position')
            ->get(['id', 'name', 'translations', 'store_id', 'parent', 'active', 'position']);

        $sections = [];
        foreach ($categories as $cat) {
            $items = $this->sectionItems($cat, $currency, $lang);
            if ($items === []) {
                continue; // a FoodMenuSection must have at least one item
            }
            $sections[] = [
                'labels' => [$this->label((string) $cat->name, '', $lang)],
                'items' => $items,
            ];
        }

        if ($sections === []) {
            return ['menus' => []];
        }

        return ['menus' => [[
            'labels' => [$this->label((string) ($store->title ?: 'Menu'), '', $lang)],
            'sections' => $sections,
        ]]];
    }

    /**
     * Items for one top-level section = the category's own active items plus every
     * active item under its subcategories (so a subcategory item is never dropped).
     *
     * @return array<int, array<string,mixed>>
     */
    private function sectionItems(MenuCategory $cat, string $currency, string $lang): array
    {
        // Loaded via ->with()->get() above → accessors localize; NOT pluck.
        $menus = collect($cat->menus ?? []);
        foreach ($cat->subcategories ?? [] as $sub) {
            $menus = $menus->concat($sub->menus ?? []);
        }

        $items = [];
        foreach ($menus as $m) {
            /** @var Menu $m */
            $name = trim((string) $m->name);
            if ($name === '') {
                continue;
            }
            $item = [
                'labels' => [$this->label($name, (string) $m->description, $lang)],
                'attributes' => ['price' => $this->money((float) $m->price, $currency)],
            ];
            $diet = $this->dietary($m);
            if ($diet !== []) {
                $item['attributes']['dietaryRestriction'] = $diet;
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string,mixed> */
    private function label(string $displayName, string $description, string $lang): array
    {
        $label = [
            'displayName' => $this->truncate($displayName, self::LABEL_MAX),
            'languageCode' => $lang,
        ];
        $description = trim($description);
        if ($description !== '') {
            $label['description'] = $this->truncate($description, self::DESC_MAX);
        }

        return $label;
    }

    /** @return array{currencyCode:string, units:int, nanos:int} */
    private function money(float $price, string $currency): array
    {
        if ($price < 0) {
            $price = 0.0;
        }
        $units = (int) floor($price);
        $nanos = (int) round(($price - $units) * 1_000_000_000);
        // Guard rounding that tips nanos to a full unit.
        if ($nanos >= 1_000_000_000) {
            $units += 1;
            $nanos -= 1_000_000_000;
        }

        return ['currencyCode' => $currency, 'units' => $units, 'nanos' => $nanos];
    }

    /** @return array<int,string> Google DietaryRestriction enum values */
    private function dietary(Menu $m): array
    {
        $out = [];
        if ((bool) ($m->is_vegan ?? false)) {
            $out[] = 'VEGAN';
        } elseif (($m->type ?? null) === 'veg') {
            $out[] = 'VEGETARIAN';
        }
        if ((bool) ($m->is_halal ?? false)) {
            $out[] = 'HALAL';
        }

        return $out;
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    private function currency(Store $store): string
    {
        return function_exists('store_currency')
            ? (string) store_currency($store->id)
            : (string) config('settings.currency_code', 'USD');
    }

    /** Store default menu language (BCP-47-ish); Google defaults to English if unusable. */
    private function languageCode(Store $store): string
    {
        $lang = (string) (store_options($store->id, 'default_language') ?: '');
        if ($lang === '') {
            $lang = substr((string) config('app.locale', 'en'), 0, 2) ?: 'en';
        }

        return $lang;
    }
}
