<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Http\Controllers\Storefront\BookingStorefrontController;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ManageLink;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Bookings\Concerns\BookingFixtures;

/**
 * P3-11: a `book` theme store with its booking engine data, for the conformance, totals and render tests — three services (one with a
 * deposit), two staff, weekly hours, the store's tax; and the three pages of the flow that need a record: a live hold (the confirm
 * page), a confirmed booking (the booking page, reschedule) and one waiting for its deposit (the pay page).
 *
 * Uses the HTTP kernel of the test case ($this->store), so holds belong to the test browser's session exactly as in production.
 */
trait BookStoreScenario
{
    use BookingFixtures;

    /**
     * @param  array<string, mixed>  $tax  restaurant_taxx_charge / restaurant_tax_type / restaurant_tax_label
     * @return array{store: Store, services: list<BookableService>}
     */
    protected function bookScenarioStore(string $currency = 'SAR', array $tax = ['restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage', 'restaurant_tax_label' => 'VAT'], int $scale = 100): array
    {
        $store = $this->servicesStore('Asia/Riyadh', $tax + ['menu_languages' => json_encode(['en', 'ar'])]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($store->id, 'store_template', 'book');
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['en', 'ar']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');
        $attrs = ['currency' => $currency];
        $cut = $this->service($store, $attrs + ['name' => 'Haircut & blow-dry', 'description' => 'A consultation, cut and finished blow-dry.', 'duration_min' => 45, 'price_minor' => 12 * $scale * 10, 'deposit_type' => 'amount', 'deposit_value' => 5 * $scale * 10, 'position' => 0]);
        $mani = $this->service($store, $attrs + ['name' => 'Gel manicure', 'description' => 'Long-wear gel colour with a high shine.', 'duration_min' => 55, 'price_minor' => 11 * $scale * 10, 'position' => 1]);
        $facial = $this->service($store, $attrs + ['name' => 'Classic facial', 'description' => 'Cleanse, exfoliate, extract and mask.', 'duration_min' => 60, 'price_minor' => 22 * $scale * 10, 'position' => 2]);
        $this->staffMember($store, [$cut, $mani, $facial], 'Maha', 0);
        $this->staffMember($store, [$cut, $mani], 'Lina', 1);
        $this->weeklyHours($store);

        return ['store' => $store, 'services' => [$cut, $mani, $facial]];
    }

    /**
     * @param  list<BookableService>  $services
     * @return array{hold: string, confirmed: string, pending: string} the live hold's public id and the two bookings' manage tokens
     */
    protected function bookScenarioRecords(Store $store, array $services): array
    {
        $holds = app(HoldService::class);
        $bookings = app(BookingService::class);
        $hold = function (string $at, array $svc) use ($store, $holds): BookingHold {
            $made = $holds->hold($store, array_map(fn ($s) => $s->public_id, $svc), null, $this->local($store, $at), 'venue', 'scenario');
            session()->put('bookings.holds.'.$made['hold']->public_id, $made['token']);
            $this->scenarioTokens[(string) $made['hold']->public_id] = $made['token'];

            return $made['hold'];
        };
        $book = function (BookingHold $h) use ($store, $bookings): Booking {
            return $bookings->book($store, (string) $h->public_id, $this->scenarioTokens[(string) $h->public_id], ['name' => 'Noura Al-Harbi', 'phone' => '+966500000001', 'source' => 'web']);
        };

        $confirmed = $book($hold('2026-10-05 11:00', [$services[1], $services[2]]));
        $confirmed->forceFill(['status' => 'confirmed'])->save();
        $pending = $book($hold('2026-10-05 13:00', [$services[0]]));
        $live = $hold('2026-10-06 10:00', [$services[0], $services[1]]);

        return ['hold' => (string) $live->public_id, 'confirmed' => ManageLink::token($confirmed), 'pending' => ManageLink::token($pending)];
    }

    /** @var array<string, string> hold public id => its token */
    protected array $scenarioTokens = [];

    /**
     * One storefront booking step, called in process (no middleware: the locale is whatever the test set): the controller's
     * method with a request that carries the test browser's session.
     *
     * @param  callable(BookingStorefrontController, Request): mixed  $call
     * @param  array<string, mixed>  $query
     */
    protected function bookStep(callable $call, array $query = []): string
    {
        $request = Request::create('/step', 'GET', $query);
        $request->setLaravelSession(app('session.store'));
        $response = $call(app(BookingStorefrontController::class), $request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }
}
