<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\GoogleOrdering;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Storefront\Services\ViewModel\CatalogPresenter;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Tests\Support\StorefrontApi\ApiFixtures;

/**
 * P5-20 part C (Google Ordering Redirect) test fixtures.
 *
 * One eligible store = a food store an owner opted in (`google_ordering_enabled` === '1') with a
 * geocoded active branch and at least one ordering switch on. Money is read through the same
 * CatalogPresenter/MoneyFormat path the storefront uses, so the feed and the storefront agree.
 */
trait GoogleOrderingFixtures
{
    protected string $goDir = '';

    /** A private feed directory (never /tmp) with every candidate config key pointed at it. */
    protected function makeFeedDir(): string
    {
        $dir = storage_path('app/testing/google-ordering/'.Str::uuid());
        File::deleteDirectory($dir);
        $this->goDir = $dir;
        config([
            'filesystems.google_ordering_feeds_path' => $dir,
            'filesystems.google_ordering_path' => $dir,
        ]);

        return $dir;
    }

    /**
     * A restaurant with an orderable catalogue and the requested ordering switches (default: all three on).
     *
     * @param  array<string, bool>  $switches
     */
    protected function foodStore(string $slug, string $title = 'Demo', array $switches = []): Store
    {
        $switches += [
            'restaurant_takeaway_order' => true,
            'restaurant_delivery_order' => true,
            'restaurant_on_table_order' => true,
        ];

        $fx = new ApiFixtures;
        $store = $fx->store($fx->owner(), $slug, $title, 'restaurant', $switches + [
            'currency_code' => 'SAR', 'currency_sign' => 'SAR',
        ]);

        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $fx->item($store, $category, (string) Str::uuid7(), $title.' Mixed grill', 80);
        $fx->item($store, $category, (string) Str::uuid7(), $title.' Shish tawook', 42);

        return $store;
    }

    protected function googleOn(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'google_ordering_enabled', '1');
    }

    /** The representative active branch that carries coordinates (RwgMerchantSource shape). */
    protected function geocodedBranch(Store $store, float $lat = 24.7136, float $lng = 46.6753): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => 'Main', 'active' => 1, 'accepting_orders' => 1,
            'timezone' => 'Asia/Riyadh', 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    /**
     * A named, geocoded, active branch for the multi-branch merchant tests, with optional
     * opening hours, phone and Google Maps place id.
     *
     * @param  array<string, array{open?: string, close?: string, closed?: bool}>  $hours
     */
    protected function branchAt(Store $store, string $name, float $lat, float $lng, array $hours = [], ?string $phone = null, ?string $googlePlaceId = null): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => $name, 'active' => 1, 'accepting_orders' => 1,
            'timezone' => 'Asia/Riyadh', 'latitude' => $lat, 'longitude' => $lng,
            'phone' => $phone, 'hours' => $hours === [] ? null : $hours,
            'google_place_id' => $googlePlaceId,
        ]);
    }

    /** @return list<string> core fulfilment mode keys the store offers now */
    protected function offeredTypes(Store $store): array
    {
        return app(StorefrontOrdering::class)->orderTypes($store);
    }

    /** Seed the active languages the storefront language list intersects against. */
    protected function seedActiveLanguages(array $codes = ['en', 'ar']): void
    {
        foreach ($codes as $code) {
            Language::updateOrCreate(
                ['code' => $code],
                ['name' => strtoupper($code), 'direction' => 'ltr', 'active' => 1]
            );
        }
    }

    /** @return array<string, mixed> */
    protected function readFeed(string $name): array
    {
        $path = $this->goDir.'/'.$name;
        $this->assertFileExists($path, $name.' was written');

        return json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The list entries of a feed envelope (the one top-level key that is a list, skipping metadata),
     * whatever the envelope names it (`merchant`, `service`, `menu`, `item`, `service_availability`…).
     *
     * @param  array<string, mixed>  $feed
     * @return list<array<string, mixed>>
     */
    /** Every item of every section of every menu in a menu feed. */
    protected function menuItemsOf(array $feed): array
    {
        $out = [];
        foreach ($feed['menu'] ?? [] as $menu) {
            foreach ($menu['section'] ?? [] as $section) {
                foreach ($section['item'] ?? [] as $item) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    protected function entriesOf(array $feed): array
    {
        foreach ($feed as $key => $value) {
            if ($key === 'metadata') {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
            if (is_array($value)) {
                foreach ($value as $inner) {
                    if (! is_array($inner)) {
                        continue;
                    }
                    foreach ($inner as $deep) {
                        if (is_array($deep) && array_is_list($deep)) {
                            return array_values(array_filter($deep, 'is_array'));
                        }
                    }
                }
            }
        }

        return [];
    }

    /** Every string anywhere in a decoded feed. @return list<string> */
    protected function stringsIn(mixed $value): array
    {
        $out = [];
        array_walk_recursive($value, function ($leaf) use (&$out): void {
            if (is_string($leaf)) {
                $out[] = $leaf;
            }
        });

        return $out;
    }

    /** Every integer anywhere in a decoded feed. @return list<int> */
    protected function intsIn(mixed $value): array
    {
        $out = [];
        array_walk_recursive($value, function ($leaf) use (&$out): void {
            if (is_int($leaf)) {
                $out[] = $leaf;
            }
        });

        return $out;
    }

    /**
     * The action links of a service/menu feed: every absolute URL that points at `/order/<kit>`.
     *
     * @param  array<string, mixed>  $feed
     * @return list<string>
     */
    protected function actionLinks(array $feed): array
    {
        return array_values(array_filter(
            $this->stringsIn($feed),
            fn (string $s): bool => preg_match('#^https?://[^\s]+/order/[a-z_]+#', $s) === 1
        ));
    }

    /** @param  array<string, mixed>  $feed */
    protected function actionLinksFor(array $feed, string $kit): array
    {
        return array_values(array_filter(
            $this->actionLinks($feed),
            fn (string $s): bool => str_contains($s, '/order/'.$kit)
        ));
    }

    /** The storefront's own visible catalogue (the same reader the view model uses). @return Collection<int, Menu> */
    protected function catalogueItems(Store $store): Collection
    {
        return app(StorefrontCatalog::class)->items($store);
    }

    /** The storefront's own minor-unit price of one item (CatalogPresenter). */
    protected function storefrontPriceMinor(Store $store, Menu $item): int
    {
        $presenter = new CatalogPresenter(
            app(StorefrontCatalog::class), $store, new MoneyFormat($store), $store->isFoodVertical(), false
        );

        return $presenter->priceMinor($item);
    }
}
