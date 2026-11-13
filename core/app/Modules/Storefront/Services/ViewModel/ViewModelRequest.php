<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * P3-02: everything one storefront view model is built from — the store, the render language, the screen and the
 * request context the controller resolved server-side (branch, scanned table, kiosk session, signed-in customer,
 * the cart, the order being tracked or rescheduled). Nothing here comes from the theme.
 *
 * Pages: home, item, cart, checkout, track, time (choose a booking time), reschedule (move an appointment).
 * P3-11 (booking engine, `book` theme): `time` / `reschedule` choose a time on the engine's calendar, `details` confirms a hold,
 * `pay` takes the deposit, `manage` is the booking page — each carries what the step computed in `$booking`; a laundry (same theme)
 * has `schedule` (choose the pickup and delivery times) and `details`.
 */
final class ViewModelRequest
{
    public const PAGES = ['home', 'item', 'cart', 'checkout', 'track', 'time', 'reschedule', 'details', 'manage', 'pay', 'schedule'];

    /**
     * @param  string|null  $orderType  core fulfilment mode key (OrderTypeKeys::TO_KIT); null = the first offered
     * @param  string|null  $table  label of the scanned table (QR), e.g. "Table 12"
     * @param  bool  $room  the scanned table is a hotel room (room service context)
     * @param  string|null  $theme  kit theme slug to render with; null = the store's theme, else its type's kit theme
     * @param  string|null  $item  item public id (item page; time page: the service)
     * @param  list<array{item: string, qty?: int, variant?: string|null, options?: list<string>, note?: string|null}>  $cart
     * @param  string|null  $date  Y-m-d in store time for slot pages; null = the first day with a free slot
     * @param  array<string, mixed>|null  $booking  P3-11: the booking-engine step's data (see BookingViewModel); null = none
     * @param  array{method: string, address: array<string, mixed>}|null  $shipping  P3-08: the shipping method of a ship cart
     * @param  string|null  $vehicle  P3-08b (aisle §13): the shopper's vehicle key (`year|make|model`, `vin:<VIN>`); read only by a store that sells by vehicle
     * @param  string|null  $onlyCategory  P4-16: a category public id; the catalogue is built from that category's items only (the "Load more" fragment of a very large catalogue needs nothing else)
     * @param  int|null  $deliveryFeeMinor  P3-09: a delivery cart's server-set fee (store fee + express fee), minor units
     */
    public function __construct(
        public readonly Store $store,
        public readonly string $locale,
        public readonly string $page = 'home',
        public readonly ?string $theme = null,
        public readonly ?Branch $branch = null,
        public readonly ?string $orderType = null,
        public readonly ?string $table = null,
        public readonly bool $room = false,
        public readonly bool $kiosk = false,
        public readonly ?Customer $customer = null,
        public readonly ?string $item = null,
        public readonly array $cart = [],
        public readonly ?string $coupon = null,
        public readonly int $tipMinor = 0,
        public readonly ?Order $order = null,
        public readonly ?string $date = null,
        public readonly ?array $booking = null,
        public readonly ?array $shipping = null,
        public readonly ?int $deliveryFeeMinor = null,
        public readonly ?string $vehicle = null,
        public readonly ?string $onlyCategory = null,
    ) {
        if (! in_array($page, self::PAGES, true)) {
            throw new \InvalidArgumentException("Unknown storefront page '{$page}'.");
        }
    }
}
