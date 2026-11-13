<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Exceptions\PlanLimitReachedException;
use App\Models\MenuCategory;
use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;

/**
 * rev/multi-vertical Lane D — D1: the service item form (DECISIONS A9).
 *
 * A NEW dashboard surface for stores whose VerticalRegistry `item_form`
 * capability is 'service' (laundry — see config/verticals.php), mirroring the
 * pattern {@see RetailItemController} (Lane A4) set for 'retail': its own
 * controller + views rather than touching ManagesMenu/StoreController (the
 * shared, food-framed item form) or RetailItemController itself (Lane A's own
 * surface, which hard-requires goods concepts — brand/weight/variant grid —
 * a service item never has, D1: "no stock/variants-as-goods").
 *
 * Persistence goes through the SAME neutral {@see MenuAdminService} engine
 * every other Menu-writing surface shares (plan limits, category-ownership
 * checks, price-change auditing) — nothing here reimplements it.
 */
class ServiceItemController extends Controller
{
    /**
     * Tenancy (G2) + vertical-shape guard shared by every action here.
     *
     * rev/multi-vertical Lane V (V4): same multi_vertical manage-surface gate
     * as RetailItemController — laundry is a non-restaurant vertical too.
     */
    private function authorizeStore(Request $request, Store $store): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        abort_unless(app(VerticalRegistry::class)->itemForm($store->store_type) === 'service', 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    public function index(Request $request, Store $store, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);

        $items = Menu::where('store_id', $store->id)
            ->with('category')
            ->orderBy('category_id')
            ->orderBy('position')
            ->paginate(25);

        return view('owner.catalog.service.index', [
            'store' => $store,
            'items' => $items,
            'categories' => MenuCategory::where('store_id', $store->id)->orderBy('name')->get(),
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    public function create(Request $request, Store $store, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);

        return view('owner.catalog.service.create', [
            'store' => $store,
            'categories' => MenuCategory::where('store_id', $store->id)->orderBy('name')->get(),
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        $data = $this->validated($request, $store);

        try {
            $item = MenuAdminService::createItem((int) $store->id, $data);
        } catch (PlanLimitReachedException $e) {
            app_alert_error(___($e->getMessage()));

            return back()->withInput();
        }

        log_activity('menu.create', $item, ['name' => $item->getRawOriginal('name')], $store->id);
        app_alert_success(___('Saved Successfully'));

        return redirect()->route('laundry.services.edit', [$store, $item]);
    }

    public function edit(Request $request, Store $store, Menu $service, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $service->store_id === (int) $store->id, 404);

        return view('owner.catalog.service.edit', [
            'store' => $store,
            'item' => $service,
            'categories' => MenuCategory::where('store_id', $store->id)->orderBy('name')->get(),
            'vocab' => $vocab->map($store->store_type),
        ]);
    }

    public function update(Request $request, Store $store, Menu $service): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $service->store_id === (int) $store->id, 404);

        $data = $this->validated($request, $store, creating: false);

        MenuAdminService::updateItem($service, $data);

        app_alert_success(___('Saved Successfully'));

        return redirect()->route('laundry.services.edit', [$store, $service]);
    }

    public function destroy(Request $request, Store $store, Menu $service): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $service->store_id === (int) $store->id, 404);

        $service->delete();
        app_alert_success(___('Deleted Successfully'));

        return redirect()->route('laundry.services.index', $store);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Store $store, bool $creating = true): array
    {
        $rules = [
            'category_id' => ['nullable', 'integer'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            // D1: "menu.pricing_unit = each|kg (additive, guarded)". No other
            // value is ever persisted — anything else falls back to 'each'.
            'pricing_unit' => ['nullable', 'in:each,kg'],
            'turnaround_hours' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'image_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'active' => ['nullable', 'boolean'],
        ];

        $validator = Validator::make($request->all(), $rules);
        $validator->validate();
        $v = $validator->validated();

        // Only a field the request actually TOUCHED lands in $data, so a partial
        // update never clobbers name/category/price back to null.
        $data = array_filter([
            'category_id' => $v['category_id'] ?? null,
            'name' => $v['name'] ?? null,
            'description' => isset($v['description']) ? strip_tags($v['description'], '<b><strong><em><i><br><p><ul><ol><li><span>') : null,
            'price' => $v['price'] ?? null,
            'pricing_unit' => $v['pricing_unit'] ?? null,
            'turnaround_hours' => array_key_exists('turnaround_hours', $v) ? $v['turnaround_hours'] : null,
        ], fn ($value) => $value !== null);

        $data['active'] = $request->has('active') ? 1 : 0;

        if ($request->hasFile('image_file')) {
            $data['image'] = image_upload($request->file('image_file'), 'storage/menu/', '500x500');
        }

        return $data;
    }
}
