<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use App\Models\MenuCategory;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use App\Storefront\StorefrontExtras;
use Illuminate\Support\Facades\Lang;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Storefront\Drops\AllergyDrop;
use Juwri\Modules\Storefront\Storefront\Drops\AnnouncementDrop;
use Juwri\Modules\Storefront\Storefront\Drops\BannerDrop;
use Juwri\Modules\Storefront\Storefront\Drops\BranchDrop;
use Juwri\Modules\Storefront\Storefront\Drops\CategoryDrop;
use Juwri\Modules\Storefront\Storefront\Drops\CustomerDrop;
use Juwri\Modules\Storefront\Storefront\Drops\FeaturesDrop;
use Juwri\Modules\Storefront\Storefront\Drops\FlagsDrop;
use Juwri\Modules\Storefront\Storefront\Drops\GiftCardsDrop;
use Juwri\Modules\Storefront\Storefront\Drops\HoursDrop;
use Juwri\Modules\Storefront\Storefront\Drops\ItemDrop;
use Juwri\Modules\Storefront\Storefront\Drops\LocalizationDrop;
use Juwri\Modules\Storefront\Storefront\Drops\MenuDrop;
use Juwri\Modules\Storefront\Storefront\Drops\OptionsDrop;
use Juwri\Modules\Storefront\Storefront\Drops\PlatformDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SettingsDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SocialDrop;
use Juwri\Modules\Storefront\Storefront\Drops\StatsDrop;
use Juwri\Modules\Storefront\Storefront\Drops\StoreDrop;
use Juwri\Modules\Storefront\Storefront\Drops\StoryDrop;
use Juwri\Modules\Storefront\Storefront\Drops\TableDrop;
use Juwri\Modules\Storefront\Storefront\Drops\VocabDrop;

/**
 * P2-2: the ONLY thing that feeds the Liquid renderer. Maps the existing
 * StoreController render-site variables (the 24-var contract, §4) into Drop
 * presenters / scalars. Passing a raw Eloquent model, `env()`, or `config()`
 * through here would be the security bug the whole isolation boundary exists
 * to prevent (shared-hosting: the Drop layer IS the boundary).
 *
 * `build()` takes an assoc array keyed like StoreController's `compact()` so the
 * two stay loosely coupled and the mapping is unit-testable without a request.
 */
class ThemeContext
{
    /** Offers strip cap (matches the Blade partial). */
    public const MAX_OFFERS = 12;

    /**
     * @param  array<string,mixed>  $v  render-site variables (post, postOptions, total_menus, …)
     * @return array<string,mixed> Liquid globals — Drops/scalars only
     */
    public function build(array $v): array
    {
        $currentLang = $v['default_menu_language'] ?? null;
        $currentLang = $currentLang instanceof Language ? $currentLang : null;

        $activeBranch = $v['activeBranch'] ?? null;
        $table = $v['table'] ?? null;
        $customer = $v['customer'] ?? null;
        $extras = is_array($v['extras'] ?? null) ? $v['extras'] : [];
        // P6-256: the store's own currency — read from the options already in hand (same source as store_currency()),
        // so building the context never needs a second settings read.
        $opts = $v['postOptions'] ?? null;
        $base = $opts !== null
            ? (string) (data_get($opts, 'currency_code') ?: config('settings.currency_code', 'USD'))
            : (isset($v['store']) ? store_currency((int) $v['store']->id) : (string) config('settings.currency_code', 'USD'));
        $currencies = DisplayRates::fromOptions($opts, $base)->codes(); // MC-31: unified parser

        return [
            'restaurant' => isset($v['store']) ? new StoreDrop($v['store']) : null,
            'options' => new OptionsDrop($v['postOptions'] ?? (object) []),
            'settings' => new SettingsDrop($v['settings'] ?? []),
            'menu' => $this->buildMenu($v['total_menus'] ?? []),
            'allergies' => $this->mapDrops($v['allergies'] ?? [], AllergyDrop::class),
            'banners' => $this->mapDrops($v['menuBanners'] ?? [], BannerDrop::class),
            'branches' => $this->mapDrops($v['branches'] ?? [], BranchDrop::class),
            'active_branch' => $activeBranch ? new BranchDrop($activeBranch) : null,
            'table' => $table ? new TableDrop($table) : null,
            'customer' => $customer ? new CustomerDrop($customer) : null,
            'localization' => new LocalizationDrop($v['menu_languages'] ?? [], $currentLang, $currencies),
            'flags' => new FlagsDrop($v['flags'] ?? []),
            'stats' => new StatsDrop((int) ($v['scans_today'] ?? 0)),
            'vocab' => $this->buildVocab(isset($v['store']) ? ($v['store']->store_type ?? null) : null),
            // Theme Studio B0.6: what the Blade themes compute in @php / layout
            // partials (StorefrontExtras, built by the render site).
            'hours' => new HoursDrop($extras['hours'] ?? []),
            'stories' => array_map(fn (array $s) => new StoryDrop($s), array_values($extras['stories'] ?? [])),
            'offers' => $this->buildOffers($v['total_menus'] ?? []),
            'social' => new SocialDrop($extras['social'] ?? []),
            'announcement' => ! empty($extras['announcement']) ? new AnnouncementDrop($extras['announcement']) : null,
            'features' => new FeaturesDrop($extras['features'] ?? []),
            // The gift cards the store SELLS — the denominations, the payment
            // methods and where to post the purchase. (The REDEEM side is the
            // runtime's own: features.gift_cards draws the code field.)
            'giftCards' => isset($v['store']) && $v['store'] instanceof Store
                ? new GiftCardsDrop($v['store'])
                : null,
            // Theme Studio follow-ups: brand + hide-branding for "Powered by".
            // Previews / demo renders get the real platform brand too.
            'platform' => new PlatformDrop(is_array($extras['platform'] ?? null)
                ? $extras['platform']
                : StorefrontExtras::platform(($v['store'] ?? null) instanceof Store ? $v['store'] : null)),
        ];
    }

    /**
     * The offers strip: items whose schedule/happy-hour price is below their
     * regular price and that aren't sold out — same rule and cap as the Blade
     * `_partials/offers-strip`.
     *
     * @param  iterable<Menu>  $items
     * @return list<ItemDrop>
     */
    protected function buildOffers(iterable $items): array
    {
        $offers = [];
        foreach ($items as $menu) {
            $drop = new ItemDrop($menu);
            if ($drop->on_offer() && ! $drop->is_sold_out()) {
                $offers[] = $drop;
                if (count($offers) >= self::MAX_OFFERS) {
                    break;
                }
            }
        }

        return $offers;
    }

    /**
     * The `vocab` global — the store's vertical nouns, resolved + translated
     * server-side (VerticalVocab → config → ___()) into a flat map, so goods /
     * services / stays Liquid themes read "Products" / "Book" / "Catalog" while
     * food stores keep today's exact words. Unknown/legacy types fall back to
     * food, so a theme never renders an empty label.
     */
    protected function buildVocab(?string $storeType): VocabDrop
    {
        $vocab = app(VerticalVocab::class);
        $group = (string) app(VerticalRegistry::class)
            ->capability($storeType, 'group', 'food');

        return new VocabDrop($vocab->map($storeType), $group);
    }

    /**
     * Server-set render registers — data for whitelisted filters/tags that must
     * NOT be reachable as a theme variable (so markup cannot forge it). Passed
     * to LiquidRenderer::render()'s $registers. The tenant id that scopes
     * `media_url`, and (Theme Studio B0.7) the trusted `widget_context` the
     * `{% widget %}` tag hands to its platform Blade partials — the raw render
     * vars stay server-side here, never exposed as Liquid variables.
     *
     * @param  array<string,mixed>  $v  same render-site variables as build()
     * @return array<string,mixed>
     */
    public function registers(array $v): array
    {
        $store = $v['store'] ?? null;
        $extras = is_array($v['extras'] ?? null) ? $v['extras'] : [];

        return [
            'store_id' => $store ? (int) $store->id : null,
            'widget_context' => $store ? [
                'store' => $store,
                'postOptions' => $v['postOptions'] ?? (object) [],
                'customer' => $v['customer'] ?? null,
                'table' => $v['table'] ?? null,
                'branches' => $v['branches'] ?? [],
                'activeBranch' => $v['activeBranch'] ?? null,
                'flags' => is_array($v['flags'] ?? null) ? $v['flags'] : [],
                // Same switches the `features` global carries (StorefrontExtras).
                'features' => is_array($extras['features'] ?? null) ? $extras['features'] : [],
                'default_menu_language' => $v['default_menu_language'] ?? null,
                // Which widget dialogs/scripts this page already wrote — an object,
                // so every section render (own RenderContext) shares it.
                'emitted' => new \ArrayObject,
            ] : null,
            // Theme Studio phase A: {% custom_block %} renders ONLY this store's
            // blocks (server-set, never a theme variable); `emitted` shares the
            // once-per-page runtime assets across section renders.
            'custom_block_context' => $store ? [
                'store' => $store,
                'emitted' => new \ArrayObject,
            ] : null,
        ];
    }

    /**
     * Group the flat, already-merged item list (`$total_menus`) into categories,
     * ordered by category position. Items keep the global-modifier merges and
     * branch price/availability adjustments StoreController already applied — the
     * Drop performs no DB work.
     *
     * @param  iterable<Menu>  $items
     */
    protected function buildMenu(iterable $items): MenuDrop
    {
        $byCategory = [];
        $uncategorised = [];
        foreach ($items as $menu) {
            $category = $menu->relationLoaded('category') ? $menu->getRelation('category') : null;
            if (! $category) {
                // B5: real data carries items whose category was deleted
                // (5003 live rows at audit time). Blade themes still show
                // them; the Liquid contract must too — synthetic bucket below.
                $uncategorised[] = $menu;

                continue;
            }
            $id = (int) $category->id;
            if (! isset($byCategory[$id])) {
                $byCategory[$id] = ['category' => $category, 'items' => []];
            }
            $byCategory[$id]['items'][] = $menu;
        }

        if ($uncategorised !== []) {
            // Read-only label lookup — a render must never create a lang key.
            $label = Lang::has('lang.other')
                ? (string) trans('lang.other', [], get_lang())
                : 'Other';
            $bucket = new MenuCategory(['position' => PHP_INT_MAX]);
            $bucket->id = 0;
            $bucket->name = $label;
            $byCategory[0] = ['category' => $bucket, 'items' => $uncategorised];
        }

        uasort(
            $byCategory,
            fn ($a, $b) => (int) ($a['category']->position ?? 0) <=> (int) ($b['category']->position ?? 0)
        );

        $categories = [];
        foreach ($byCategory as $entry) {
            $categories[] = new CategoryDrop($entry['category'], $entry['items']);
        }

        return new MenuDrop($categories);
    }

    /**
     * @param  iterable<mixed>  $models
     * @param  class-string  $dropClass
     * @return array<int,mixed>
     */
    protected function mapDrops(iterable $models, string $dropClass): array
    {
        $out = [];
        foreach ($models as $model) {
            $out[] = new $dropClass($model);
        }

        return $out;
    }
}
