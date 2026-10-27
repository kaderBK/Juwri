<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Models\SupplierPayment;
use Juwri\Modules\Inventory\Services\Inventory\SupplierBalanceService;

class SupplierController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // P6-224: a staff session is bound to its own store
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = $request->query('store_id') ?: dashboard_current_store()?->id;

        if ($storeId && ! in_array((int) $storeId, $storeIds, true)) {
            abort(403);
        }

        $query = Supplier::whereIn('store_id', $storeIds);
        if ($storeId) {
            $query->where('store_id', (int) $storeId);
        }

        $suppliers = $query->orderBy('name')->get();
        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);

        return view('owner.inventory.suppliers', compact('suppliers', 'stores', 'storeId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! in_array((int) $data['store_id'], $this->ownerRestaurantIds(), true)) {
            abort(403);
        }

        Supplier::create($data + ['active' => true]);

        return back()->with('success', ___('Supplier added.'));
    }

    public function update(Request $request, int $supplier)
    {
        $model = Supplier::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($supplier);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['nullable', 'boolean'],
        ]);

        $model->update([
            'name' => $data['name'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'lead_time_days' => $data['lead_time_days'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'active' => $request->boolean('active'),
        ]);

        return back()->with('success', ___('Supplier updated.'));
    }

    public function destroy(int $supplier)
    {
        $model = Supplier::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($supplier);
        $model->delete();

        return back()->with('success', ___('Supplier deleted.'));
    }

    /**
     * E7: supplier detail — outstanding balance (received-goods value − payments),
     * a record-payment form, and the interleaved statement.
     */
    public function show(int $supplier): View
    {
        $model = Supplier::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($supplier);

        $balance = SupplierBalanceService::balance($model);
        $charges = SupplierBalanceService::chargesTotal($model);
        $paid = SupplierBalanceService::paymentsTotal($model);
        $statement = SupplierBalanceService::statement($model);

        return view('owner.inventory.supplier-show', [
            'supplier' => $model,
            'balance' => $balance,
            'charges' => $charges,
            'paid' => $paid,
            'statement' => $statement,
        ]);
    }

    /** E7: record a payment to a supplier. §5 fields: amount, method, note. */
    public function recordPayment(Request $request, int $supplier): RedirectResponse
    {
        $model = Supplier::whereIn('store_id', $this->ownerRestaurantIds())->findOrFail($supplier);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'method' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        SupplierPayment::create([
            'supplier_id' => $model->id,
            'amount' => round((float) $data['amount'], 3),
            'method' => $data['method'] ?? null,
            'note' => $data['note'] ?? null,
            'user_id' => auth()->id(),
            'paid_at' => now(),
        ]);

        return back()->with('success', ___('Payment recorded.'));
    }
}
