<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers\User;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;

/**
 * Public storefront endpoints for the aurelia (hotel) theme: date-range room
 * availability + direct booking. Thin wrappers over the existing hotel engine
 * (BookingService / RoomType) so the premium theme can search + reserve without
 * the QR/room-token guest flow. Returns enabled:false (200) for a store that is
 * not a hotel (or has hotel_mode off), so a stray client call degrades quietly.
 */
class StorefrontHotelController extends Controller
{
    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    /** GET {slug}/hotel/rooms/availability?check_in&check_out&guests — room types + availability for the range. */
    public function availability(string $slug, Request $request): JsonResponse
    {
        $store = $this->store($slug);
        if (! HotelService::enabled($store->id)) {
            return response()->json(['enabled' => false, 'rooms' => []]);
        }

        $tz = 'Asia/Riyadh';
        $in = $this->parseDate($request->query('check_in'), Carbon::now($tz)->toDateString());
        $out = $this->parseDate($request->query('check_out'), Carbon::parse($in)->addDay()->toDateString());
        if (Carbon::parse($out)->lte(Carbon::parse($in))) {
            $out = Carbon::parse($in)->addDay()->toDateString();
        }
        $nights = max(1, Carbon::parse($in)->diffInDays(Carbon::parse($out)));

        $avail = BookingService::typeAvailability((int) $store->id, $in, $out);
        $types = RoomType::forRestaurant((int) $store->id)->where('active', true)->orderBy('sort')->get();

        // Room presentation (bed/desc/image) lives in a StoreOption map keyed by
        // room-type name (room_types has no such columns) — seeded by
        // VerticalShowcaseSeeder::buildHotelExtras().
        // store_options() auto-decodes a JSON value into a stdClass — normalize to
        // a nested assoc array either way (string, object, or array).
        $presRaw = store_options((int) $store->id, 'hotel_room_pres');
        if (is_string($presRaw)) {
            $pres = json_decode($presRaw, true) ?: [];
        } elseif (is_object($presRaw) || is_array($presRaw)) {
            $pres = json_decode(json_encode($presRaw), true) ?: [];
        } else {
            $pres = [];
        }

        $rooms = $types->map(function (RoomType $t) use ($avail, $store, $nights, $pres) {
            $rate = (float) ($t->base_rate ?? 0);
            $p = $pres[$t->name] ?? [];
            $img = $p['image'] ?? null;

            return [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'bed' => $p['bed'] ?? null,
                'occupancy' => (int) ($t->max_occupancy ?? 2),
                'desc' => strip_tags((string) ($p['desc'] ?? '')),
                'rate' => $rate,
                'rate_formatted' => price_symbol_format($rate),
                'nights' => $nights,
                'total' => $rate * $nights,
                'total_formatted' => price_symbol_format($rate * $nights),
                'available' => (int) ($avail[(int) $t->id] ?? 0),
                'image' => $img ? media_url('storage/menu/', $img, $store->id) : '',
            ];
        })->values();

        return response()->json([
            'enabled' => true,
            'check_in' => $in,
            'check_out' => $out,
            'nights' => $nights,
            'rooms' => $rooms,
        ]);
    }

    /** POST {slug}/hotel/rooms/book — create a reservation via the hotel engine. */
    public function book(string $slug, Request $request): JsonResponse
    {
        $store = $this->store($slug);
        if (! HotelService::enabled($store->id)) {
            return response()->json(['success' => false, 'message' => ___('Booking is not available for this store.')], 422);
        }

        $data = $request->validate([
            'room_type_id' => 'required|integer',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'guest_name' => 'required|string|max:120',
            'guest_phone' => 'nullable|string|max:40',
            'adults' => 'nullable|integer|min:1|max:20',
            'children' => 'nullable|integer|min:0|max:20',
            'rate_plan_id' => 'nullable|integer',
        ]);

        try {
            $booking = BookingService::create((int) $store->id, [
                'room_type_id' => (int) $data['room_type_id'],
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'guest_name' => $data['guest_name'],
                'guest_phone' => $data['guest_phone'] ?? null,
                'adults' => (int) ($data['adults'] ?? 2),
                'children' => (int) ($data['children'] ?? 0),
                'rate_plan_id' => $data['rate_plan_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => ___('Those dates are no longer available. Please try different dates.')], 422);
        }

        return response()->json([
            'success' => true,
            'code' => $booking->code,
            'total_formatted' => price_symbol_format((float) $booking->quoted_total),
        ]);
    }

    private function parseDate(?string $v, string $default): string
    {
        if (! $v) {
            return $default;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
