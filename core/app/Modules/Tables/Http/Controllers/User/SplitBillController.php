<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Services\SplitBillService;

class SplitBillController extends Controller
{
    private function ownedOrder(int $orderId): Order
    {
        return Order::forOwner(auth()->id())->findOrFail($orderId); // S13
    }

    public function show(int $order)
    {
        $model = $this->ownedOrder($order);
        $check = TableCheck::where('order_id', $model->id)->with('payments')->latest('id')->first();

        return view('owner.orders.split', [
            'order' => $model,
            'check' => $check,
        ]);
    }

    public function store(Request $request, int $order)
    {
        $model = $this->ownedOrder($order);

        $data = $request->validate([
            'parts' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        SplitBillService::createEvenSplit($model, (int) $data['parts']);

        return redirect()->route('orders.split.show', $model->id)
            ->with('success', ___('Bill split into :n shares.', ['n' => $data['parts']]));
    }

    public function pay(Request $request, int $share)
    {
        $payment = CheckPayment::whereHas('tableCheck', function ($q) {
            $q->whereIn('store_id', owner_store_ids()); // S13
        })->findOrFail($share);

        $data = $request->validate([
            'payer_name' => ['nullable', 'string', 'max:120'],
            'method' => ['nullable', 'in:cash,card,online'],
        ]);

        SplitBillService::markSharePaid($payment, $data['payer_name'] ?? null, $data['method'] ?? 'cash');

        return back()->with('success', ___('Share marked paid.'));
    }

    public function destroy(int $order)
    {
        $model = $this->ownedOrder($order);
        $check = TableCheck::where('order_id', $model->id)->latest('id')->first();

        if ($check) {
            if ($check->payments()->where('status', 'paid')->exists()) {
                return back()->with('error', ___('Cannot remove a split that already has payments.'));
            }
            $check->payments()->delete();
            $check->delete();
        }

        return back()->with('success', ___('Split removed.'));
    }
}
