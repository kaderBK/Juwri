<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Contracts;

use Juwri\Core\Models\Store;

/**
 * P3-11: the booking engine as the storefront view model reads it (the `book` kit theme renders from the view model
 * alone). Plain arrays with public ids only; money in minor units of the service's currency. Implemented by Bookings'
 * StorefrontBookingsService.
 */
interface StorefrontBookings
{
    /** Does this store sell its services through the booking engine (it has at least one active bookable service)? */
    public function inUse(Store $store): bool;

    /** D13 "no hours = no bookings": hours, staff, a service and the plan are in place. */
    public function open(Store $store): bool;

    /**
     * The active services that can be done at `$location` (`venue` | `at_home`), by position.
     *
     * @return list<array{id: string, name: string, description: string, duration_min: int, price_minor: int, currency: string, deposit_minor: int, venue: bool, at_home: bool}>
     */
    public function services(Store $store, string $location = 'venue'): array;

    /**
     * Active staff with the services they do (service public ids); empty when the store turned staff choice off.
     *
     * @return list<array{id: string, name: string, role: string, photo: string, service_ids: list<string>}>
     */
    public function staff(Store $store): array;

    /** The earliest free start of the first service (any staff), as an ISO-8601 instant, or null when none within the horizon. */
    public function nextFree(Store $store, string $location = 'venue'): ?string;

    /**
     * The store's booking rules the page words: cancellation window, deposit refunds, at-home, staff choice, zone.
     *
     * @return array{timezone: string, cancel_window_hours: int, deposit_refunds: bool, at_home: bool, staff_choice: bool, policy_note: string, tax_label: string}
     */
    public function rules(Store $store): array;

    /**
     * The quote of a selection (1-4 services, public ids): the booking total the booking will carry. Null when a
     * service is unknown, inactive or cannot be done at the location.
     *
     * @param  list<string>  $serviceIds
     * @return array{currency: string, subtotal_minor: int, tax_minor: int, total_minor: int, deposit_minor: int, due_later_minor: int, duration_min: int, lines: list<array{id: string, name: string, duration_min: int, price_minor: int, deposit_minor: int}>}|null
     */
    public function quote(Store $store, array $serviceIds, string $location = 'venue'): ?array;
}
