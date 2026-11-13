<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;

/**
 * P5-20 — Google Ordering Redirect Services feed.
 *
 * One service per merchant per OFFERED core order type, each carrying ACTION LINKS built by
 * OrderLinks::url() for every storefront locale (the deep links back into the store's own
 * ordering page). Service data (ETA, delivery fee, minimum order, currency) comes from the
 * SAME source the storefront reads, so Google and the storefront never disagree:
 *  - offered types + prep minutes: Ordering's StorefrontOrdering contract;
 *  - delivery fee: Branch::effectiveDeliveryFee (exactly as StorefrontCart / DeliverySlots read it);
 *  - minimum order: Branch::effectiveMinOrder (major units, exactly as ViewModelBuilder reads it);
 *  - money: MoneyFormat (integer minor units + ISO currency).
 *
 * P5-31: a per-branch merchant advertises its branch's own prep time, delivery fee and minimum
 * order where the branch sets them (null = the store's value), exactly what an order there charges.
 */
final class ServiceFeedBuilder
{
    public function __construct(
        private readonly GoogleOrderingMerchantSource $source,
        private readonly OrderLinks $links,
    ) {}

    public function filename(): string
    {
        return 'service_feed.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $ordering = app(StorefrontOrdering::class);
        $services = [];

        foreach ($this->source->merchants() as $m) {
            $store = $m['store'];
            $parsed = GoogleOrderingMerchantSource::parseMerchantId((string) $m['merchant_id']);
            // Only a per-branch merchant carries its branch in the deep link; a single-branch
            // merchant stays byte-identical to the historic store-level output.
            $linkBranch = $parsed !== null && $parsed['branch'] !== null ? $m['branch'] : null;
            $money = new MoneyFormat($store);
            $offered = $ordering->orderTypes($store);
            if ($offered === []) {
                continue;
            }
            $locales = array_values(array_unique(array_map('strval', $this->links->locales($store))));
            // P5-31: a per-branch merchant advertises its branch's own prep time / delivery fee / minimum order
            // (each falls back to the store's); a store-level merchant stays on the store's values.
            $prep = $ordering->prepTimeMinutes($store, $linkBranch);
            $deliveryFee = $money->major(Branch::effectiveDeliveryFee((int) $store->id, $linkBranch));
            $min = Branch::effectiveMinOrder((int) $store->id, $linkBranch);
            $minimum = $min !== null ? $money->major($min) : null;

            foreach ($offered as $coreType) {
                $kit = OrderTypeKeys::toKit($coreType);
                if ($kit === null) {
                    continue;
                }
                $actionLinks = [];
                foreach ($locales as $locale) {
                    $url = $this->links->url($store, $coreType, $locale === '' ? null : $locale, $linkBranch);
                    if ($url === null) {
                        continue; // store does not offer this type in this locale
                    }
                    $actionLinks[] = ['url' => $url, 'locale' => $locale === '' ? $this->defaultLocale($store) : $locale];
                }
                if ($actionLinks === []) {
                    continue; // no link → no Google order button
                }

                $service = [
                    'merchant_id'  => $m['merchant_id'],
                    'service_id'   => 'order-'.$kit,
                    'name'         => $this->name($coreType),
                    'order_type'   => $kit,
                    'action_links' => $actionLinks,
                    'currency'     => $money->currency,
                ];
                if ($prep !== null) {
                    $service['eta_min_minutes'] = $prep;
                }
                if ($coreType === 'delivery') {
                    $service['delivery_fee'] = $deliveryFee;
                }
                if ($minimum !== null) {
                    $service['minimum_order'] = $minimum;
                }
                $services[] = $service;
            }
        }

        return [
            'metadata' => GoogleOrderingFeedBuilder::metadata(),
            'service'  => $services,
        ];
    }

    private function name(string $coreType): string
    {
        return match ($coreType) {
            'takeaway', 'in_store', 'pickup_slot' => 'Pickup',
            'delivery' => 'Delivery',
            'on-table' => 'Dine-in',
            'room_service' => 'Room service',
            'ship' => 'Shipping',
            'appointment' => 'Appointment',
            'at_home' => 'At home',
            default => ucfirst(str_replace('_', ' ', $coreType)),
        };
    }

    /** The locale the unprefixed storefront address serves (its default language). */
    private function defaultLocale(Store $store): string
    {
        $language = StoreSettings::get((int) $store->id, 'default_language');
        if (is_string($language) && $language !== '') {
            return $language;
        }
        foreach (StoreSettings::array((int) $store->id, 'menu_languages') as $code) {
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return (string) config('app.locale', 'en');
    }
}
