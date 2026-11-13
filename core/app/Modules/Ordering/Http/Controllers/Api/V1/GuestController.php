<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Models\Customer;
use App\Services\Crm\RfmService;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;

/**
 * K6: read-only guest-data API (CDP). A merchant's own stack can pull guest
 * profiles + their RFM segment, and segment counts, over the owner token —
 * strictly scoped to the token's restaurant. Contact details are hashed (never
 * raw phone/email, never in a URL) so this can feed a downstream CDP without
 * leaking PII.
 */
class GuestController extends Controller
{
    use CapsPerPage;
    use OwnerScoped;

    public function index(Request $request, int $storeId)
    {
        $this->ownedRestaurant($request, $storeId);

        $rfm = new RfmService()->compute($storeId);
        $segByCustomer = collect($rfm['scores'])->map(fn ($s) => $s['segment']);

        $guests = Customer::where('store_id', $storeId)
            ->when($request->query('segment'), function ($q, $seg) use ($segByCustomer) {
                $ids = $segByCustomer->filter(fn ($v) => (string) $v === (string) $seg)->keys()->all() ?: [0];
                $q->whereIn('id', $ids);
            })
            ->orderByDesc('total_spent_minor') // P1-05
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 50));

        return response()->json($guests->through(fn ($c) => [
            'id' => $c->id,
            'contact_hash' => hash('sha256', (string) ($c->phone ?: $c->email ?: $c->id)),
            'first_order_at' => optional($c->created_at)->toIso8601String(),
            'last_order_at' => optional($c->last_order_at)->toIso8601String(),
            'orders_count' => (int) $c->orders_count,
            'lifetime_spend' => (float) $c->total_spent,
            'channel' => $c->source,
            'rfm_segment' => $segByCustomer[$c->id] ?? null,
        ]));
    }

    public function segments(Request $request, int $storeId)
    {
        $this->ownedRestaurant($request, $storeId);

        $rfm = new RfmService()->compute($storeId);

        return response()->json([
            'store_id' => $storeId,
            'segments' => (object) $rfm['segments'],
        ]);
    }
}
