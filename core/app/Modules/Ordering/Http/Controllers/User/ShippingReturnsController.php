<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\OrderItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Contracts\Returns;
use Juwri\Modules\Ordering\Models\ReturnRequest;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Models\ShippingZone;

/**
 * P3-08 — the owner's retail operations: shipping zones and methods (fees in the store currency, stored in minor
 * units), and goods returns (approve / reject, goods received, complete as exchange, store credit or refund).
 *
 * Tenant scoping: every page and action reads the current store of the dashboard actor (`store_id` query, checked
 * against the actor's stores); a zone / method / return is looked up inside that store, so a foreign id is a 404.
 * Ids are scalar route parameters (no model binding).
 */
class ShippingReturnsController extends Controller
{
    use ResolvesCurrentStore;

    public function shipping(Request $request): View
    {
        $store = $this->currentRestaurant($request);

        return view('owner.retail.shipping', [
            'store' => $store,
            'rid' => (int) $store->id,
            'currency' => $this->currency($store),
            'zones' => ShippingZone::query()->where('store_id', $store->id)->orderBy('position')->orderBy('id')->get(),
            'methods' => ShippingMethod::query()->where('store_id', $store->id)->orderBy('position')->orderBy('id')->get(),
            'branches' => Branch::query()->where('store_id', $store->id)->get(['id', 'name']),
            'stores' => Store::query()->whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function saveZone(Request $request, ?int $zone = null): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $row = $zone !== null ? ShippingZone::query()->where('store_id', $store->id)->findOrFail($zone) : new ShippingZone(['store_id' => $store->id]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('shipping_zones', 'code')->where('store_id', $store->id)->ignore($row->id)],
            'name' => ['required', 'string', 'max:120'],
            'countries' => ['required', 'string', 'max:500'],
            'regions' => ['nullable', 'string', 'max:2000'],
            'cities' => ['nullable', 'string', 'max:2000'],
            'postal_prefixes' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        $countries = array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', $data['countries'])), fn ($c) => preg_match('/^[A-Z]{2}$/', $c) === 1));
        if ($countries === []) {
            return back()->withErrors(['countries' => ___('Enter at least one two-letter country code, e.g. SA.')])->withInput();
        }
        $row->fill([
            'code' => $data['code'],
            'name' => $data['name'],
            'countries' => $countries,
            'regions' => $this->list($data['regions'] ?? null),
            'cities' => $this->list($data['cities'] ?? null),
            'postal_prefixes' => $this->list($data['postal_prefixes'] ?? null),
            'active' => $request->boolean('active', true),
            'position' => (int) ($data['position'] ?? 0),
        ])->save();

        return back()->with('success', ___('Shipping zone saved.'));
    }

    public function deleteZone(Request $request, int $zone): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        ShippingZone::query()->where('store_id', $store->id)->findOrFail($zone)->delete();

        return back()->with('success', ___('Shipping zone deleted.'));
    }

    public function saveMethod(Request $request, ?int $method = null): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $row = $method !== null ? ShippingMethod::query()->where('store_id', $store->id)->findOrFail($method) : new ShippingMethod(['store_id' => $store->id]);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['standard', 'express', 'pickup_in_store'])],
            'shipping_zone_id' => ['nullable', 'integer', Rule::exists('shipping_zones', 'id')->where('store_id', $store->id)],
            'pickup_branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $store->id)],
            'name' => ['required', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:80'],
            // an https link with the {tracking} placeholder (Laravel's url rule refuses the braces)
            'tracking_url_template' => ['nullable', 'string', 'max:500', 'regex:#^https://[A-Za-z0-9.-]+(?::\d+)?/[^\s<>"]*\{tracking\}[^\s<>"]*$#'],
            'fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'free_over' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'min_days' => ['required', 'integer', 'min:0', 'max:90'],
            'max_days' => ['required', 'integer', 'min:0', 'max:90', 'gte:min_days'],
            'cutoff_time' => ['nullable', 'date_format:H:i'],
            'delivery_days' => ['nullable', 'array'],
            'delivery_days.*' => ['integer', 'between:0,6'],
            'active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        $pickup = $data['kind'] === 'pickup_in_store';
        if ($pickup && empty($data['pickup_branch_id'])) {
            return back()->withErrors(['pickup_branch_id' => ___('Choose the branch for pickup in store.')])->withInput();
        }
        if (! $pickup && empty($data['shipping_zone_id'])) {
            return back()->withErrors(['shipping_zone_id' => ___('Choose the zone this method ships to.')])->withInput();
        }
        $currency = $this->currency($store);
        $days = array_values(array_unique(array_map('intval', (array) ($data['delivery_days'] ?? []))));
        sort($days);
        $row->fill([
            'kind' => $data['kind'],
            'shipping_zone_id' => $pickup ? null : (int) $data['shipping_zone_id'],
            'pickup_branch_id' => $pickup ? (int) $data['pickup_branch_id'] : null,
            'name' => $data['name'],
            'carrier' => $data['carrier'] ?? null,
            'tracking_url_template' => $data['tracking_url_template'] ?? null,
            'fee_minor' => Money::minorFromMajor((float) $data['fee'], $currency),
            'free_over_minor' => isset($data['free_over']) && $data['free_over'] !== '' ? Money::minorFromMajor((float) $data['free_over'], $currency) : null,
            'currency_code' => $currency,
            'min_days' => (int) $data['min_days'],
            'max_days' => (int) $data['max_days'],
            'cutoff_time' => $data['cutoff_time'] ?? null,
            'delivery_days' => $days === [] || count($days) === 7 ? null : $days,
            'active' => $request->boolean('active', true),
            'position' => (int) ($data['position'] ?? 0),
        ])->save();

        return back()->with('success', ___('Shipping method saved.'));
    }

    public function deleteMethod(Request $request, int $method): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        ShippingMethod::query()->where('store_id', $store->id)->findOrFail($method)->delete();

        return back()->with('success', ___('Shipping method deleted.'));
    }

    public function returns(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $returns = ReturnRequest::query()->where('store_id', $store->id)
            ->orderByRaw("case when status = 'requested' then 0 when status in ('approved', 'received') then 1 else 2 end")
            ->orderByDesc('id')->limit(100)->get();
        $itemIds = $returns->flatMap(fn (ReturnRequest $r) => array_column((array) $r->lines, 'order_item_id'))->unique()->all();
        $items = OrderItem::query()->whereIn('id', $itemIds)->with(['menu', 'variant'])->get()->keyBy('id');

        return view('owner.retail.returns', [
            'store' => $store,
            'rid' => (int) $store->id,
            'returns' => $returns,
            'items' => $items,
            'stores' => Store::query()->whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function decideReturn(Request $request, int $return, Returns $returns): RedirectResponse
    {
        $row = $this->returnRow($request, $return);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->run(fn () => $returns->decide($row, $data['decision'] === 'approve', $data['note'] ?? null),
            $data['decision'] === 'approve' ? ___('Return approved.') : ___('Return rejected.'));
    }

    public function receiveReturn(Request $request, int $return, Returns $returns): RedirectResponse
    {
        $row = $this->returnRow($request, $return);

        return $this->run(fn () => $returns->receive($row, $request->boolean('restock', true)), ___('Goods marked as received.'));
    }

    public function completeReturn(Request $request, int $return, Returns $returns): RedirectResponse
    {
        $row = $this->returnRow($request, $return);

        return $this->run(fn () => $returns->complete($row), match ($row->resolution) {
            'store_credit' => ___('Return completed. Store credit was added to the customer.'),
            'refund' => ___('Return completed and refunded.'),
            default => ___('Return completed.'),
        });
    }

    private function returnRow(Request $request, int $id): ReturnRequest
    {
        $store = $this->currentRestaurant($request);

        return ReturnRequest::query()->where('store_id', $store->id)->findOrFail($id);
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'status' => ___('This return has moved on. Reload the page.'),
                'no_customer' => ___('This order has no customer account to credit.'),
                'store_credit_off' => ___('Store credit is not enabled on your plan. Refund through your payment provider instead.'),
                'currency' => ___('This order was paid in another currency than the customer store credit.'),
                'refund_refused' => ___('The payment provider refused the refund.'),
                'refund_cap' => ___('This order has already been refunded.'),
                'resolution' => ___('Returns refunds are off for this store.'),
                default => ___('This request could not be completed.'),
            });
        }

        return back()->with('success', $success);
    }

    /** @return list<string>|null */
    private function list(?string $value): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));

        return $items === [] ? null : array_slice($items, 0, 200);
    }

    private function currency(Store $store): string
    {
        return strtoupper(store_currency((int) $store->id));
    }
}
