<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\Shipping;
use Juwri\Modules\Ordering\Data\ShippingOption;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;

/**
 * P3-08 / P3-06b phase B (kit/api `POST /shipping/quote`): the shipping methods a store offers for an address and a
 * cart — fee (free from the method's threshold on the items subtotal), earliest / latest delivery date, cut-off,
 * carrier, pickup branch — each with its View Model `Choice` for display. The cart is priced by the same server
 * quote as `POST /quote` (as a `ship` order), so the free-shipping threshold reads the subtotal the shopper sees.
 * Shared by the headless API and the storefront's goods checkout.
 */
final class StorefrontShippingQuote
{
    public function __construct(private readonly Shipping $shipping) {}

    /**
     * @param  array<string, mixed>  $address
     * @return array{methods: list<array<string, mixed>>, zone: string|null, ships_to_address: bool}
     */
    public function answer(Store $store, array $address, int $itemsSubtotalMinor, ?string $selected = null): array
    {
        $zone = $this->shipping->zoneFor($store, $address);
        $options = $this->shipping->options($store, $address, $itemsSubtotalMinor);
        $money = new MoneyFormat($store);
        $methods = [];
        foreach ($options as $i => $option) {
            $methods[] = $this->method($store, $option, $money, $selected !== null ? $option->publicId() === $selected : $i === 0);
        }

        return ['methods' => $methods, 'zone' => $zone?->code, 'ships_to_address' => $zone !== null];
    }

    /**
     * Validation of a ShippingQuoteRequest body (openapi.json).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws StorefrontApiException
     */
    public static function validate(array $body): array
    {
        $rules = array_intersect_key(StorefrontCart::rules(), array_flip([
            'lines', 'lines.*', 'lines.*.line_id', 'lines.*.item_id', 'lines.*.variant_id', 'lines.*.option_ids', 'lines.*.option_ids.*',
            'lines.*.qty', 'lines.*.note', 'address.country', 'address.address_id', 'address.line1', 'address.line2', 'address.city',
            'address.region', 'address.postal_code', 'address.lat', 'address.lng', 'address.notes',
        ]));
        $rules['address'] = ['required', 'array'];
        $validator = Validator::make($body, $rules);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors);
        }

        return $body;
    }

    /** @return array<string, mixed> kit/api ShippingMethod */
    private function method(Store $store, ShippingOption $option, MoneyFormat $money, bool $selected): array
    {
        $method = $option->method;
        $fee = $money->money($option->feeMinor);
        $out = [
            'id' => $option->publicId(),
            'kind' => (string) $method->kind,
            'carrier' => $method->carrier !== null && trim($method->carrier) !== '' ? (string) $method->carrier : null,
            'fee' => $fee,
            'earliest_date' => $option->earliest->toDateString(),
            'latest_date' => $option->latest->toDateString(),
            'cutoff_at' => $option->cutoffAt?->toIso8601String(),
        ];
        $branch = null;
        if ($method->kind === 'pickup_in_store' && $method->pickup_branch_id !== null) {
            $branch = Branch::query()->where('store_id', $store->id)->find($method->pickup_branch_id);
            if ($branch !== null && $branch->public_id !== null) {
                $out['pickup_branch_id'] = (string) $branch->public_id;
            }
        }
        $display = [
            'id' => $option->publicId(),
            'label' => (string) $method->name,
            'sub' => $this->sub($option, $branch),
            'amount' => $fee,
            'amount_label' => $option->feeMinor === 0 ? ___('Free') : (string) $fee['formatted'],
        ];
        if ($selected) {
            $display['selected'] = true;
        }
        $display['icon'] = match ((string) $method->kind) {
            'express' => 'zap',
            'pickup_in_store' => 'store',
            default => 'truck',
        };
        $out['display'] = $display;

        return $out;
    }

    private function sub(ShippingOption $option, ?Branch $branch): string
    {
        $date = fn (CarbonImmutable $d): string => $d->locale(app()->getLocale())->translatedFormat('D j M');
        if ($option->method->kind === 'pickup_in_store') {
            $ready = ___('ready :date', ['date' => $date($option->earliest)]);

            return $branch !== null ? $branch->name.' · '.$ready : $ready;
        }
        $min = (int) $option->method->min_days;
        $max = max($min, (int) $option->method->max_days);
        $by = ___('by :date', ['date' => $date($option->latest)]);

        return $min === $max ? $by : ___(':min–:max days', ['min' => (string) $min, 'max' => (string) $max]).' · '.$by;
    }
}
