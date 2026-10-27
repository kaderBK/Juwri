<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Support\Sql;

/** Owner-token read access to a restaurant's customer list (merchant app / CRM). */
class CustomerController extends Controller
{
    use CapsPerPage;
    use OwnerScoped;

    public function index(Request $request, int $storeId)
    {
        $this->ownedRestaurant($request, $storeId);

        $customers = Customer::where('store_id', $storeId)
            // P1-17: Scout database search (name/phone/email).
            ->when($request->query('search'), fn ($q, $s) => $q->whereSearch((string) $s))
            // F0-4: `?sort=created` (newest-registered first) is what a Zapier/Make
            // "New Customer" polling trigger needs — the default `recent` sort
            // (most-recently-active, for the CRM app) buries a brand-new customer
            // whose last_order_at is null. Both carry an `id` tiebreak for a stable
            // total order across polls.
            ->when($request->query('sort') === 'created',
                fn ($q) => $q->orderByDesc('id'),
                fn ($q) => Sql::orderByDescNullsLast($q, 'last_order_at')->orderByDesc('id'))
            ->paginate($this->perPage($request, 20));

        return response()->json($customers->through(fn ($c) => $this->present($c)));
    }

    /**
     * B4-a (integrations pack): create a customer — the target of the Zapier / Make /
     * n8n "Create Customer" action. Only the safe profile fields are accepted (never
     * password / oauth / consent internals). Returns the full customer, 201.
     */
    public function store(Request $request, int $storeId): JsonResponse
    {
        $this->ownedRestaurant($request, $storeId);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:2000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:40',
        ]);
        $data['store_id'] = $storeId;

        $customer = Customer::create($data);

        return response()->json($this->present($customer), 201);
    }

    /** @return array<string,mixed> */
    private function present(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'orders_count' => $c->orders_count,
            'total_spent' => $c->total_spent,
            'last_order_at' => $c->last_order_at,
            'tags' => $c->tags,
            'created_at' => $c->created_at,
        ];
    }
}
