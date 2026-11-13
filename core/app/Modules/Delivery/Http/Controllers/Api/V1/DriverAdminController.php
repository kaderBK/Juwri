<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\Api\V1;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Owner-token driver management. Creating (or rotating) a driver returns a raw
 * driver-app token exactly once; the driver app then authenticates with it
 * against the `/api/v1/driver/*` endpoints.
 */
class DriverAdminController extends Controller
{
    use OwnerScoped;

    public function index(Request $request)
    {
        $drivers = Driver::where('owner_id', $this->ownerId($request))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => $d->toApiArray());

        return response()->json(['data' => $drivers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'phone'         => 'nullable|string|max:40',
            'email'         => 'nullable|email|max:160',
            'store_id' => 'nullable|integer',
        ]);

        if (!empty($data['store_id'])) {
            $this->ownedRestaurant($request, (int) $data['store_id']);
        }

        $raw = Str::random(48);
        $driver = Driver::create(array_merge($data, [
            'owner_id' => $this->ownerId($request),
            'token'    => hash('sha256', $raw),
            'active'   => true,
        ]));

        // Raw token shown once — the driver enters it in their app.
        return response()->json(array_merge($driver->toApiArray(), ['token' => $raw]), 201);
    }

    public function update(Request $request, int $id)
    {
        $driver = Driver::where('owner_id', $this->ownerId($request))->findOrFail($id);

        $data = $request->validate([
            'name'          => 'sometimes|string|max:120',
            'phone'         => 'nullable|string|max:40',
            'email'         => 'nullable|email|max:160',
            'active'        => 'nullable|boolean',
            'store_id' => 'nullable|integer',
        ]);
        if (!empty($data['store_id'])) {
            $this->ownedRestaurant($request, (int) $data['store_id']);
        }

        $driver->update($data);

        return response()->json($driver->fresh()->toApiArray());
    }

    public function destroy(Request $request, int $id)
    {
        Driver::where('owner_id', $this->ownerId($request))->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** POST /drivers/{id}/rotate-token — invalidate the old token, issue a new one. */
    public function rotateToken(Request $request, int $id)
    {
        $driver = Driver::where('owner_id', $this->ownerId($request))->findOrFail($id);

        $raw = Str::random(48);
        $driver->update(['token' => hash('sha256', $raw)]);

        return response()->json(array_merge($driver->toApiArray(), ['token' => $raw]));
    }
}
