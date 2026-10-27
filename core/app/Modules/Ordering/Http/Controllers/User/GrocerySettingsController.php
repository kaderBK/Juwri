<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\DeliverySlotRule;

/**
 * P3-09: the owner's grocery settings — delivery / collection time slots (hourly windows, orders per hour, lead time,
 * days ahead, express fee + time, radius), the weighed-item tolerance (cap %) and the weighed-difference refund switch
 * (D7, off), the "If it's out of stock" options with their picker actions (API-22 / API-25 / API-30), and per item:
 * sold by weight (weight steps) or a pack size (unit prices).
 *
 * Tenant scoping: every action works on the dashboard actor's current store (`store_id` query, checked against the
 * actor's stores); an item id resolves inside that store only (a foreign id is a 404).
 */
class GrocerySettingsController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private readonly GroceryCatalog $grocery) {}

    public function edit(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $currency = strtoupper((string) store_currency((int) $store->id));
        $group = $this->grocery->substitutionGroup($store);

        return view('owner.orders.grocery-settings', [
            'store' => $store,
            'currency' => $currency,
            'rule' => DeliverySlotRule::query()->where('store_id', $store->id)->whereNull('branch_id')->first(),
            'capPercent' => $this->grocery->capPercent($store),
            'refundsWeighed' => StoreSettings::bool((int) $store->id, 'refunds_weighed_difference'),
            'group' => $group,
            'pickerActions' => $group !== null ? collect($this->grocery->pickerActions($group))->keyBy('option_id')->all() : [],
            'defaultOption' => $this->grocery->defaultSubstitution($store),
            'items' => Menu::query()->where('store_id', $store->id)->orderBy('name')->limit(500)->get(),
            'stores' => Store::query()->whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function saveSlots(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'active' => ['nullable', 'boolean'],
            'windows' => ['nullable', 'string', 'max:500'],
            'capacity_per_hour' => ['required', 'integer', 'min:0', 'max:1000'],
            'lead_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'days_ahead' => ['required', 'integer', 'min:1', 'max:14'],
            'express_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'express_eta_from' => ['nullable', 'integer', 'min:1', 'max:600'],
            'express_eta_to' => ['nullable', 'integer', 'min:1', 'max:600', 'gte:express_eta_from'],
            'radius_km' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);
        $windows = self::parseWindows((string) ($data['windows'] ?? ''));
        if ($windows === null) {
            return back()->withInput()->with('error', ___('Write the times as 09:00-12:00, 16:00-21:00.'));
        }
        $currency = strtoupper((string) store_currency((int) $store->id));
        $express = isset($data['express_fee']) && $data['express_fee'] !== '';
        DeliverySlotRule::query()->updateOrCreate(
            ['store_id' => $store->id, 'branch_id' => null],
            [
                'account_id' => $store->account_id,
                'active' => (bool) ($data['active'] ?? false),
                'windows' => $windows,
                'capacity_per_hour' => (int) $data['capacity_per_hour'],
                'lead_minutes' => (int) $data['lead_minutes'],
                'days_ahead' => (int) $data['days_ahead'],
                'express_fee_minor' => $express ? Money::minorFromMajor((float) $data['express_fee'], $currency) : null,
                'express_eta_from' => $express ? (int) ($data['express_eta_from'] ?? 30) : null,
                'express_eta_to' => $express ? (int) ($data['express_eta_to'] ?? 60) : null,
                'radius_km' => isset($data['radius_km']) && $data['radius_km'] !== '' ? (string) $data['radius_km'] : null,
                'currency_code' => $currency,
            ]
        );

        return back()->with('success', ___('Delivery times saved.'));
    }

    public function saveWeighed(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'cap_percent' => ['required', 'integer', 'min:0', 'max:50'],
            'refunds_weighed_difference' => ['nullable', 'boolean'],
        ]);
        StoreSettings::setMany((int) $store->id, [
            'grocery_weighed_cap_percent' => (int) $data['cap_percent'],
            'refunds_weighed_difference' => (bool) ($data['refunds_weighed_difference'] ?? false),
        ]);

        return back()->with('success', ___('Weighed item settings saved.'));
    }

    public function saveSubstitutions(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'options' => ['array', 'max:6'],
            'options.*.id' => ['nullable', 'string', 'max:64'],
            'options.*.title' => ['nullable', 'string', 'max:120'],
            'options.*.action' => ['nullable', 'in:offer_replacement,call_customer,remove_item'],
            'options.*.fallback' => ['nullable', 'in:offer_replacement,remove_item'],
            'default' => ['nullable', 'integer', 'min:0', 'max:5'],
        ]);
        $options = [];
        $defaultIndex = (int) ($data['default'] ?? 0);
        $defaultAt = null;
        foreach (array_values((array) ($data['options'] ?? [])) as $i => $option) {
            $title = trim((string) ($option['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if ($i === $defaultIndex) {
                $defaultAt = count($options);
            }
            $options[] = [
                'id' => isset($option['id']) && $option['id'] !== '' ? (string) $option['id'] : null,
                'title' => $title,
                'action' => (string) ($option['action'] ?? 'remove_item'),
                'fallback' => isset($option['fallback']) ? (string) $option['fallback'] : null,
            ];
        }
        if ($options === []) {
            return back()->with('error', ___('Add at least one option.'));
        }
        $group = $this->grocery->saveSubstitutionGroup($store, (string) ($data['title'] ?? ''), $options);
        $active = $group->modifiers->where('active', true)->sortBy('position')->values();
        $default = $active->get($defaultAt ?? 0);
        StoreSettings::set((int) $store->id, 'substitution_default_option', $default !== null ? (string) $default->public_id : '');

        return back()->with('success', ___('Out-of-stock options saved.'));
    }

    public function saveItem(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'item' => ['required', 'integer', 'min:1'],
            'sell' => ['required', 'in:unit,pack,weighed'],
            'steps' => ['required_if:sell,weighed', 'nullable', 'string', 'max:200'],
            'pack_size' => ['required_if:sell,pack', 'nullable', 'numeric', 'gt:0', 'max:100000'],
            'pack_unit' => ['required_if:sell,pack', 'nullable', 'in:g,kg,ml,l,pc'],
        ]);
        $item = Menu::query()->where('store_id', $store->id)->whereKey((int) $data['item'])->first();
        abort_if($item === null, 404);
        $attributes = (array) ($item->getAttribute('attributes') ?? []);
        unset($attributes['pack_size'], $attributes['pack_unit']);

        if ($data['sell'] === 'weighed') {
            $grams = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $data['steps']) ?: []), fn (int $g) => $g >= 1 && $g <= 100000)));
            sort($grams);
            if ($grams === [] || count($grams) > 10) {
                return back()->with('error', ___('Enter one to ten weights in grams, e.g. 250, 500, 1000.'));
            }
            $item->forceFill(['attributes' => $attributes])->save();
            $this->grocery->syncWeightSteps($item->refresh(), $grams);

            return back()->with('success', ___('Item sold by weight.'));
        }
        $attributes['sold_by_weight'] = false;
        if ($data['sell'] === 'pack') {
            $attributes['pack_size'] = (float) $data['pack_size'];
            $attributes['pack_unit'] = (string) $data['pack_unit'];
        }
        $item->forceFill(['attributes' => $attributes])->save();

        return back()->with('success', ___('Item saved.'));
    }

    /**
     * "09:00-12:00, 16:00-21:00" → [["09:00","12:00"],["16:00","21:00"]]; empty → []; anything malformed → null.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    public static function parseWindows(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[,;\n]+/', $text) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(([01]\d|2[0-3]):[0-5]\d)\s*[-–]\s*(([01]\d|2[0-3]):[0-5]\d|24:00)$/u', $part, $m) !== 1) {
                return null;
            }
            if ($m[3] !== '24:00' && strcmp($m[3], $m[1]) <= 0) {
                return null;
            }
            $out[] = [$m[1], $m[3]];
        }

        return count($out) <= 8 ? $out : null;
    }
}
