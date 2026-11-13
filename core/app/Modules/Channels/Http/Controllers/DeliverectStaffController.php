<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers;

use App\Models\StaffMember;
use App\Services\FeatureAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Services\Aggregators\DeliverectProvider;

/**
 * A8 (integrations pack): staff-facing busy-mode + product snooze for Deliverect,
 * driven from the staff PWA (lane D wires the toggle). StaffTokenAuth resolves the
 * acting staff → their restaurant; the Deliverect connection and the
 * `aggregator_deliverect` gate are both keyed off that store, never client input.
 *
 * The provider seams (setBusy / snoozeProduct) persist onto the connection today;
 * the outbound Deliverect Store-API call fires once staging creds land (A9).
 */
class DeliverectStaffController extends Controller
{
    /** Resolve the acting staff's Deliverect connection, or null if none/off. */
    private function connection(Request $request): ?AggregatorConnection
    {
        $staff = StaffMember::find((int) $request->attributes->get('_staff_id'));
        if (! $staff) {
            return null;
        }
        if (! FeatureAccess::storeAllows((int) $staff->store_id, 'aggregator_deliverect')) {
            return null;
        }

        return AggregatorConnection::where('provider', 'deliverect')
            ->where('store_id', $staff->store_id)
            ->first();
    }

    /** GET — current busy flag + the snoozed PLU list, for the PWA to render. */
    public function status(Request $request): JsonResponse
    {
        $conn = $this->connection($request);
        if (! $conn) {
            return response()->json(['error' => 'No active Deliverect connection.'], 404);
        }
        $settings = $conn->settings ?? [];

        return response()->json([
            'busy' => (bool) ($settings['busy'] ?? false),
            'snoozed_plus' => array_keys((array) ($settings['snoozed_plus'] ?? [])),
        ]);
    }

    /** POST {busy} — flip busy mode for the store's Deliverect channels. */
    public function busy(Request $request): JsonResponse
    {
        $conn = $this->connection($request);
        if (! $conn) {
            return response()->json(['error' => 'No active Deliverect connection.'], 404);
        }
        $busy = $request->boolean('busy');
        new DeliverectProvider()->setBusy($conn, $busy);

        return response()->json(['busy' => $busy]);
    }

    /** POST {menu_id, snoozed} — snooze/unsnooze one item (86 toggle) on Deliverect. */
    public function snooze(Request $request): JsonResponse
    {
        $conn = $this->connection($request);
        if (! $conn) {
            return response()->json(['error' => 'No active Deliverect connection.'], 404);
        }
        $data = $request->validate([
            'menu_id' => 'required|integer',
            'snoozed' => 'required|boolean',
        ]);
        new DeliverectProvider()->snoozeProduct($conn, (int) $data['menu_id'], (bool) $data['snoozed']);

        return response()->json(['menu_id' => (int) $data['menu_id'], 'snoozed' => (bool) $data['snoozed']]);
    }
}
