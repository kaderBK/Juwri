<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Edition;

use App\Models\Order;
use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\BookingService;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;
use Juwri\Modules\Hotel\Services\Hotel\RateService;

/**
 * P1-13: the Hotel module's {@see RoomCharge} — delegates to the existing hotel services, so answers are the same
 * as when Community code called them directly. The booking lookups were in the Agents module (StorefrontTools,
 * Agents CustomerTools).
 */
final class HotelRoomCharge implements RoomCharge
{
    public function enabled(mixed $store): bool
    {
        return HotelService::enabled($store);
    }

    public function available(mixed $store): bool
    {
        return HotelService::available($store);
    }

    public function settings(int $storeId): array
    {
        return HotelService::settings($storeId);
    }

    public function trayCharge(int $storeId): array
    {
        return HotelService::trayCharge($storeId);
    }

    public function tokenRequired(int $storeId): bool
    {
        return HotelRoomToken::enabled($storeId);
    }

    public function mintToken(Model $room): string
    {
        return HotelRoomToken::mint(self::room($room));
    }

    public function verifyToken(Model $room, ?string $token): bool
    {
        return HotelRoomToken::verify(self::room($room), $token);
    }

    public function activeStayForRoom(int $storeId, int $roomId): ?Model
    {
        return HotelFolioService::activeStayForRoom($storeId, $roomId);
    }

    /** @return Collection<int, covariant Model> */
    public function checkedInStays(int $storeId): Collection
    {
        return HotelStay::where('store_id', $storeId)->where('status', 'checked_in')->get();
    }

    public function guestNameMatches(Model $stay, ?string $name): bool
    {
        return HotelFolioService::guestNameMatches(self::stay($stay), $name);
    }

    public function chargeOrderForTender(Model $order, Model $stay): array
    {
        return HotelFolioService::chargeOrderForTender(self::order($order), self::stay($stay));
    }

    public function orderChargeReversalState(Model $order): string
    {
        return HotelFolioService::orderChargeReversalState(self::order($order));
    }

    public function reverseOrderCharge(Model $order): array
    {
        return HotelFolioService::reverseOrderCharge(self::order($order));
    }

    /** The current stay's requests only: a new guest never sees the previous occupant's requests. */
    public function serviceRequests(int $storeId, int $roomId, int $limit): Collection
    {
        $stay = HotelFolioService::activeStayForRoom($storeId, $roomId);
        if (! $stay) {
            return new Collection;
        }

        return ServiceRequest::where('store_id', $storeId)
            ->where('table_id', $roomId)
            ->where('stay_id', $stay->id)
            ->whereIn('status', ['open', 'in_progress', 'done'])
            ->latest('id')->limit($limit)->get();
    }

    public function statement(Model $stay): array
    {
        return HotelFolioService::statement(self::stay($stay));
    }

    /** HPMS P2 (was Agents StorefrontTools::availableRoomTypes): the public booking page's own contract. */
    public function availableRoomTypes(int $storeId, string $checkIn, string $checkOut): array
    {
        if (! Schema::hasTable('hotel_bookings')) {
            return [];
        }

        try {
            $avail = BookingService::typeAvailability($storeId, $checkIn, $checkOut);
            $plan = RateService::ensureDefaultPlan($storeId);
            $types = RoomType::forRestaurant($storeId)
                ->where('active', true)->orderBy('sort')->orderBy('id')->get();

            $cards = [];
            foreach ($types as $type) {
                $quote = RateService::quote($type, $plan, $checkIn, $checkOut);
                $cards[] = [
                    'id' => (int) $type->id,
                    'name' => $type->name,
                    'base_rate' => (float) $type->base_rate,
                    'available' => (int) ($avail[(int) $type->id] ?? 0),
                    'nightly_total' => (float) $quote['total'],
                ];
            }

            return $cards;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Was Agents CustomerMcpController::myBookings (hotel part; now Agents CustomerTools). */
    public function customerBookings(int $storeId, int $customerId, int $limit): array
    {
        if (! Schema::hasTable('hotel_bookings')) {
            return [];
        }

        return HotelBooking::where('store_id', $storeId)
            ->where('customer_id', $customerId)
            ->with('roomType')
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (HotelBooking $b) => [
                'kind' => 'hotel',
                'code' => $b->code,
                'status' => $b->status,
                'room_type' => optional($b->roomType)->name,
                'check_in' => optional($b->check_in)->toDateString(),
                'check_out' => optional($b->check_out)->toDateString(),
                'nights' => $b->nightsCount(),
                'adults' => (int) $b->adults,
                'children' => (int) $b->children,
                'quoted_total' => $b->quoted_total !== null ? (float) $b->quoted_total : null,
                'currency' => $b->currency,
            ])->values()->all();
    }

    public function upsertStay(array $match, array $values): Model
    {
        return HotelStay::updateOrCreate($match, $values);
    }

    private static function room(Model $room): StoreTable
    {
        if (! $room instanceof StoreTable) {
            throw new \InvalidArgumentException('A hotel room is a StoreTable row.');
        }

        return $room;
    }

    private static function stay(Model $stay): HotelStay
    {
        if (! $stay instanceof HotelStay) {
            throw new \InvalidArgumentException('Not a hotel stay.');
        }

        return $stay;
    }

    private static function order(Model $order): Order
    {
        if (! $order instanceof Order) {
            throw new \InvalidArgumentException('Not an order.');
        }

        return $order;
    }
}
