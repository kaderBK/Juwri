<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use App\Services\Verticals\VerticalRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Contracts\BookingApi;
use Juwri\Modules\Bookings\Data\BookingRefusal;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiFeatures;
use Juwri\Modules\Storefront\Services\Api\StorefrontBookingView;
use Juwri\Modules\Storefront\Services\Api\StorefrontIdempotency;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderPlacement;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-06b phase D (REVIEW, Opus: holds, deposits, tenancy) — the headless booking API on the P3-10 engine (kit/api
 * `getBookingStaff`, `getBookingDays`, `getBookingSlots`, `createBooking`, `getBooking`, `cancelBooking`,
 * `rescheduleBooking`; booking holds and their release ride on `createHold` / `releaseHold`, see
 * StorefrontSlotsController; the services quote on `quoteCart`, see StorefrontCheckoutController).
 *
 *  - Every operation needs the plan feature `booking_api` (403 `booking_api_not_enabled`, API-5) and a services store
 *    (404 otherwise); listings, holds and new bookings also need the store set up (D13: 409 `bookings_not_open`).
 *  - The store comes from the token only; a booking only through its signed manage token (book §7) — never by id.
 *  - "Verify guest phone" (API-7): booking, cancel and reschedule also need a customer token of the same store whose
 *    phone is verified (403 `phone_verification_required`); on a booking the token's phone wins over the body.
 *  - Reschedule mode (API-8): `X-Manage-Token` on staff / days / slots / holds lists and holds only same-price times.
 *  - Writes take an `Idempotency-Key` (UUIDv7): the same key + body answers the first response again
 *    (`Idempotent-Replayed: true`), another body 409 `idempotency_conflict`. Every answer is `no-store`.
 */
class StorefrontBookingController extends Controller
{
    /** How long a write's answer is kept for a replay of its key. */
    private const REPLAY_SECONDS = 86400;

    public function __construct(
        private readonly BookingApi $bookings,
        private readonly StorefrontBookingView $view,
        private readonly StorefrontIdempotency $idempotency,
    ) {}

    /** GET /api/v1/storefront/booking/staff — BookingStaffResponse. */
    public function staff(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        $this->manageBooking($ctx, $request); // a foreign or wrong manage token is a 404 here too
        $ids = $this->ids($request->query('service_ids'), 'service_ids', true);

        return $this->json($this->view->staff($this->call($ctx, fn () => $this->bookings->staff($ctx->store, $ids))), 200, $ctx);
    }

    /** GET /api/v1/storefront/booking/days — BookingDaysResponse. */
    public function days(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        $this->open($ctx);
        $q = $this->validated($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'staff_id' => ['sometimes', 'string', 'max:64'],
            'at_home' => ['sometimes', 'in:true,false,1,0'],
        ]);
        $reschedule = $this->manageBooking($ctx, $request);
        $ids = $this->ids($request->query('service_ids'), 'service_ids', $reschedule === null);
        $this->noOptions($request->query('option_ids'));
        $from = (string) ($q['from'] ?? CarbonImmutable::now($this->bookings->timezone($ctx->store))->toDateString());
        $days = $this->call($ctx, fn () => $this->bookings->days($ctx->store, $ids, $this->staffId($q), $this->location($q), $from, (int) ($q['days'] ?? 14), $reschedule));

        return $this->json($this->view->days($ctx->store, $days), 200, $ctx);
    }

    /** GET /api/v1/storefront/booking/slots — BookingSlotsResponse. */
    public function slots(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        $this->open($ctx);
        $q = $this->validated($request->query(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['sometimes', 'string', 'max:64'],
            'at_home' => ['sometimes', 'in:true,false,1,0'],
        ]);
        $reschedule = $this->manageBooking($ctx, $request);
        $ids = $this->ids($request->query('service_ids'), 'service_ids', $reschedule === null);
        $this->noOptions($request->query('option_ids'));
        $date = (string) $q['date'];
        $staffId = $this->staffId($q);
        $location = $this->location($q);
        $listing = $this->call($ctx, fn () => $this->bookings->slots($ctx->store, $ids, $staffId, $location, $date, $reschedule));
        $strip = $this->call($ctx, fn () => $this->bookings->days($ctx->store, $ids, $staffId, $location, $this->stripStart($ctx->store, $date), 7, $reschedule));
        $next = null;
        if (array_filter($listing['slots'], fn (array $s) => $s['free']) === []) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->bookings->timezone($ctx->store));
            $next = $day !== null ? $this->bookings->nextFree($ctx->store, $ids, $staffId, $location, $day->utc(), $reschedule) : null;
        }

        return $this->json($this->view->slots($ctx->store, $date, $listing, $strip, $reschedule, $next), 200, $ctx);
    }

    /**
     * POST /api/v1/storefront/holds with `kind = booking_slot` — Hold (201). Called by StorefrontSlotsController.
     *
     * @param  array<string, mixed>  $body
     */
    public function hold(Request $request, StorefrontApiContext $ctx, array $body, string $key): Response
    {
        $this->gate($ctx);
        $this->open($ctx);
        $reschedule = $this->manageBooking($ctx, $request);
        $ids = $this->ids($body['service_ids'] ?? null, 'service_ids', $reschedule === null);
        $this->noOptions($body['option_ids'] ?? null);
        $where = $this->coords($body);
        $hash = StorefrontOrderPlacement::requestHash($body + ['_manage' => $reschedule?->public_id]);

        return $this->idempotency->run($ctx, 'booking_hold', $key, $hash, BookingHold::MINUTES * 60, function () use ($ctx, $request, $body, $ids, $reschedule, $where) {
            $holder = hash('sha256', 'sft:'.$ctx->token->getKey().'|'.(string) $request->ip());
            $made = $this->call($ctx, fn () => $this->bookings->hold($ctx->store, (string) $body['slot_id'], $ids, $holder, $reschedule, $where));
            // API-15: a kiosk books walk-ins for today only
            $tz = $this->bookings->timezone($ctx->store);
            if ($ctx->isKiosk() && CarbonImmutable::instance($made['hold']->starts_at)->setTimezone($tz)->toDateString() !== CarbonImmutable::now($tz)->toDateString()) {
                $this->bookings->releaseHold($ctx->store, $made['hold'], $made['token']);
                throw StorefrontApiException::validation(['slot_id' => [___('At the kiosk you can book a time today only.')]]);
            }

            return $this->json($this->view->hold($ctx->store, $made['hold'], $made['token'], (string) $body['slot_id'], $reschedule), 201, $ctx);
        });
    }

    /**
     * Is this quote body a services cart of this store (every line a bookable service)?
     *
     * @param  array<string, mixed>  $body
     */
    public function isBookingCart(StorefrontApiContext $ctx, array $body): bool
    {
        $lines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
        $ids = array_map(fn ($l) => is_array($l) ? (string) ($l['item_id'] ?? '') : '', $lines);

        return $ids !== [] && $this->bookings->areServices($ctx->store, $ids);
    }

    /**
     * POST /api/v1/storefront/quote for a services cart — QuoteResponse: the services, the total and the deposit /
     * due-later split (informational), the payment modes the deposit allows and what the booking still needs.
     *
     * @param  array<string, mixed>  $body
     */
    public function quote(StorefrontApiContext $ctx, array $body): JsonResponse
    {
        app()->setLocale($ctx->locale);
        $this->gate($ctx);
        $this->validated($body, [
            'order_type' => ['required', 'string', 'max:32'],
            'lines' => ['required', 'array', 'min:1', 'max:4'],
            'lines.*.item_id' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'integer', 'in:1'],
            'lines.*.line_id' => ['sometimes', 'string', 'max:64'],
            'lines.*.option_ids' => ['sometimes', 'array', 'max:40'],
            'slot_id' => ['sometimes', 'string', 'max:64'],
            'hold_id' => ['sometimes', 'string', 'max:64'],
            'payment_method_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment_mode' => ['sometimes', 'in:pay_later,hosted'],
        ]);
        $location = match ((string) $body['order_type']) {
            'appointment' => 'venue',
            'at_home' => $ctx->isKiosk() ? throw new StorefrontApiException(422, 'invalid_order_type', ___('The selected order type is not available for this store.')) : 'at_home',
            // API-15: a kiosk walk-in is a visit at the venue, today
            'walk_in' => $ctx->isKiosk() ? 'venue' : throw new StorefrontApiException(422, 'invalid_order_type', ___('The selected order type is not available for this store.')),
            default => throw new StorefrontApiException(422, 'invalid_order_type', ___('The selected order type is not available for this store.')),
        };
        foreach ((array) $body['lines'] as $line) {
            $this->noOptions($line['option_ids'] ?? null);
        }
        $ids = array_map(fn (array $l) => (string) $l['item_id'], (array) $body['lines']);
        $lineIds = array_map(fn (array $l) => isset($l['line_id']) ? (string) $l['line_id'] : '', (array) $body['lines']);
        $lineIds = array_map(fn (string $id, int $i) => $id !== '' ? $id : 'ln_'.($i + 1), $lineIds, array_keys($lineIds));
        if (isset($body['hold_id'])) {
            $hold = $this->bookings->findHold($ctx->store, (string) $body['hold_id']);
            if ($hold === null || $this->bookings->holdServiceIds($hold) !== $ids || (string) $hold->location !== $location) {
                throw StorefrontApiException::validation(['hold_id' => [___('This hold is not for these services.')]]);
            }
        }
        $q = $this->call($ctx, fn () => $this->bookings->quote($ctx->store, $ids, $location));
        $modes = $this->view->paymentModes($ctx->store, $q, $ctx->isKiosk());
        $requires = [];
        if ($location === 'at_home') {
            $requires[] = 'address';
        }
        if (! isset($body['hold_id'])) {
            $requires[] = 'hold';
        }
        // API-15: never for a kiosk walk-in
        if (! $ctx->isKiosk() && StoreSettings::bool((int) $ctx->store->id, 'booking_verify_guest_phone') && ($ctx->customer === null || $ctx->customer->phone_verified_at === null)) {
            $requires[] = 'verified_phone';
        }
        if (($body['payment_mode'] ?? null) === 'hosted') {
            $requires[] = 'return_url';
        }
        $out = $this->view->quoteResponse($ctx->store, $q, $lineIds, isset($body['payment_method_id']) ? (string) $body['payment_method_id'] : null,
            StorefrontBookingView::hash($ctx->store, $location, $q), $requires, $ctx->isKiosk());
        if (isset($body['payment_mode']) && ! in_array($body['payment_mode'], $modes, true)) {
            $out['warnings'][] = ['code' => 'payment_mode_unavailable', 'message' => ___('This payment option is not available for this booking.')];
        }

        return $this->json($out, 200, $ctx);
    }

    /** DELETE /api/v1/storefront/holds/{hold_id} of a booking hold — 204 or HoldReleased (200). */
    public function release(Request $request, StorefrontApiContext $ctx, BookingHold $hold): Response
    {
        $this->gate($ctx);
        $cancelled = $this->call($ctx, fn () => $this->bookings->releaseHold($ctx->store, $hold, (string) $request->header('X-Hold-Token', '')));
        if ($cancelled === null) {
            return response('', 204, ['Cache-Control' => 'no-store']);
        }

        return $this->json([
            'hold_id' => (string) $hold->public_id,
            'released' => true,
            'cancelled' => [
                'kind' => 'booking',
                'public_id' => (string) $cancelled->public_id,
                'number' => (string) $cancelled->number,
                'status' => 'cancelled',
                'manage_token' => $this->bookings->manageToken($cancelled),
            ],
            'payment' => $this->view->payment($ctx->store, $cancelled),
        ], 200, $ctx);
    }

    /** POST /api/v1/storefront/bookings — BookingCreated (201). */
    public function store(Request $request): Response
    {
        $ctx = $this->context($request);
        $this->open($ctx);
        $key = StorefrontOrderPlacement::idempotencyKey($request);
        $body = (array) $request->json()->all();
        $this->validated($body, [
            'hold_id' => ['required', 'string', 'max:64'],
            'service_ids' => ['required', 'array', 'min:1', 'max:4'],
            'service_ids.*' => ['required', 'string', 'max:64'],
            'option_ids' => ['sometimes', 'array', 'max:40'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'quote_hash' => ['required', 'string', 'max:64'],
            'customer' => ['required', 'array'],
            'customer.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'customer.phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'customer.email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'customer.marketing_opt_in' => ['sometimes', 'boolean'],
            'payment' => ['sometimes', 'array'],
            'payment.mode' => ['required_with:payment', 'in:pay_later,hosted'],
            'payment.method_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment.return_url' => ['required_if:payment.mode,hosted', 'string', 'url:https,http', 'max:2000'],
            'address' => ['sometimes', 'array'],
        ]);
        $this->noOptions($body['option_ids'] ?? null);
        $verified = $this->verifiedPhone($ctx);

        $response = $this->idempotency->run($ctx, 'booking_create', $key, StorefrontOrderPlacement::requestHash($body), self::REPLAY_SECONDS, function () use ($ctx, $request, $body, $key, $verified) {
            $hold = $this->bookings->findHold($ctx->store, (string) $body['hold_id']);
            if ($hold === null) {
                throw new StorefrontApiException(404, 'not_found', ___('This hold was not found.'));
            }
            $ids = $this->ids($body['service_ids'], 'service_ids', true);
            if ($ids !== $this->bookings->holdServiceIds($hold)) {
                throw StorefrontApiException::validation(['service_ids' => [___('The services must be the ones the time was held for.')]]);
            }
            $location = (string) $hold->location;
            $q = $this->call($ctx, fn () => $this->bookings->quote($ctx->store, $ids, $location));
            $hash = StorefrontBookingView::hash($ctx->store, $location, $q);
            if (! hash_equals($hash, (string) $body['quote_hash'])) {
                throw new StorefrontApiException(409, 'quote_changed', ___('The total changed. Check the new total and confirm again.'), null, [
                    'quote' => $this->view->quoteResponse($ctx->store, $q, [], null, $hash, []),
                ]);
            }
            $modes = $this->view->paymentModes($ctx->store, $q, $ctx->isKiosk());
            $mode = (string) ($body['payment']['mode'] ?? ($modes[0] ?? 'pay_later'));
            if (! in_array($mode, $modes, true)) {
                throw new StorefrontApiException(422, 'payment_mode_unavailable', ___('This payment option is not available for this booking.'));
            }
            $methodId = $body['payment']['method_id'] ?? null;
            if ($methodId !== null && $methodId !== ($mode === 'hosted' ? 'deposit' : 'venue')) {
                throw new StorefrontApiException(422, 'payment_method_unavailable', ___('This payment method is not available for this booking.'));
            }
            $returnUrl = $mode === 'hosted' ? (string) $body['payment']['return_url'] : null;
            if ($returnUrl !== null && ! StorefrontOrderPlacement::originAllowed($ctx, $returnUrl)) {
                throw new StorefrontApiException(422, 'return_url_not_allowed', ___('The return_url origin is not in this token\'s allowed origins.'));
            }
            $customer = (array) $body['customer'];
            $address = (array) ($body['address'] ?? []);
            $made = $this->call($ctx, fn () => $this->bookings->book($ctx->store, (string) $body['hold_id'], (string) $request->header('X-Hold-Token', ''), [
                'name' => trim((string) ($customer['name'] ?? ($ctx->customer !== null ? $ctx->customer->name : ''))),
                'phone' => $verified ?? trim((string) ($customer['phone'] ?? '')),
                'email' => $customer['email'] ?? null,
                'note' => $body['notes'] ?? null,
                'address' => isset($address['line1']) ? trim(implode(', ', array_filter([(string) $address['line1'], (string) ($address['line2'] ?? ''), (string) ($address['city'] ?? '')]))) : null,
                'lat' => isset($address['lat']) && is_numeric($address['lat']) ? (float) $address['lat'] : null,
                'lng' => isset($address['lng']) && is_numeric($address['lng']) ? (float) $address['lng'] : null,
                'source' => $ctx->isKiosk() ? 'kiosk' : 'api',
                // API-15: a kiosk walk-in — today only, no deposit step, no guest phone check
                'walk_in' => $ctx->isKiosk(),
            ], $key, $returnUrl));

            return $this->json($this->view->booking($ctx->store, $made['booking']) + ['replayed' => $made['replayed']], 201, $ctx);
        });

        return $this->replayed($response);
    }

    /** GET /api/v1/storefront/bookings/{manage_token} — BookingResponse. */
    public function show(Request $request, string $manage_token): JsonResponse
    {
        $ctx = $this->context($request);

        $booking = $this->managed($ctx, $manage_token);

        return $this->json($this->view->booking($ctx->store, $booking, $this->view->policy($ctx->store, $booking)), 200, $ctx);
    }

    /** POST /api/v1/storefront/bookings/{manage_token}/cancel — BookingResponse with the deposit outcome. */
    public function cancel(Request $request, string $manage_token): Response
    {
        $ctx = $this->context($request);
        $key = StorefrontOrderPlacement::idempotencyKey($request);
        $booking = $this->managed($ctx, $manage_token);
        $this->assertOwnPhone($ctx, $booking);

        return $this->idempotency->run($ctx, 'booking_cancel', $key, StorefrontOrderPlacement::requestHash(['booking' => $booking->public_id]), self::REPLAY_SECONDS, function () use ($ctx, $booking) {
            $outcome = $this->call($ctx, fn () => $this->bookings->cancel($booking));
            $fresh = $booking->fresh() ?? $booking;

            return $this->json($this->view->booking($ctx->store, $fresh, $this->view->policyNote($ctx->store, $fresh, $outcome)), 200, $ctx);
        });
    }

    /** POST /api/v1/storefront/bookings/{manage_token}/reschedule — BookingResponse (no money moves, API-8). */
    public function reschedule(Request $request, string $manage_token): Response
    {
        $ctx = $this->context($request);
        $key = StorefrontOrderPlacement::idempotencyKey($request);
        $booking = $this->managed($ctx, $manage_token);
        $this->assertOwnPhone($ctx, $booking);
        $body = (array) $request->json()->all();
        $this->validated($body, ['hold_id' => ['required', 'string', 'max:64'], 'notes' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->idempotency->run($ctx, 'booking_reschedule', $key, StorefrontOrderPlacement::requestHash($body + ['_booking' => $booking->public_id]), self::REPLAY_SECONDS, function () use ($ctx, $request, $booking, $body) {
            $moved = $this->call($ctx, fn () => $this->bookings->reschedule($ctx->store, $booking, (string) $body['hold_id'], (string) $request->header('X-Hold-Token', '')));

            return $this->json($this->view->booking($ctx->store, $moved, ___('Your booking is moved. Same price, nothing to pay.')), 200, $ctx);
        });
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** The call's context after the booking gates (plan feature, services store). */
    private function context(Request $request): StorefrontApiContext
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        app()->setLocale($ctx->locale);
        $this->gate($ctx);

        return $ctx;
    }

    /** API-5: `booking_api` on the plan; and a services store (other store types have no booking engine). */
    public function gate(StorefrontApiContext $ctx): void
    {
        if (! $this->bookings->apiAllowed($ctx->store)) {
            $refusal = StorefrontApiFeatures::refusal(StorefrontApiFeatures::BOOKING_API);
            $data = (array) $refusal->getData(true);

            throw new StorefrontApiException(403, (string) $data['code'], (string) $data['message'], null, ['feature' => StorefrontApiFeatures::BOOKING_API]);
        }
        $registry = app(VerticalRegistry::class);
        $config = $registry->config((string) ($ctx->store->store_type ?: $registry->default()));
        if (($config['themes'] ?? null) !== 'service') {
            throw StorefrontApiException::notFound();
        }
    }

    /** D13: no hours, staff or services yet — "Bookings open soon". */
    private function open(StorefrontApiContext $ctx): void
    {
        if (! $this->bookings->open($ctx->store)) {
            throw new StorefrontApiException(409, 'bookings_not_open', ___('Bookings open soon.'));
        }
    }

    /** The booking of an `X-Manage-Token` (reschedule mode), or null without the header; 404 for a foreign/wrong one. */
    private function manageBooking(StorefrontApiContext $ctx, Request $request): ?Booking
    {
        $token = trim((string) $request->header('X-Manage-Token', ''));

        return $token === '' ? null : $this->managed($ctx, $token);
    }

    private function managed(StorefrontApiContext $ctx, string $token): Booking
    {
        $booking = $this->bookings->byManageToken($ctx->store, $token);
        if (! $booking instanceof Booking) {
            throw StorefrontApiException::notFound();
        }

        return $booking;
    }

    /**
     * API-7: with "Verify guest phone" on, the verified phone of the caller's customer token (else 403). Null when the
     * rule is off, and for a kiosk walk-in (API-15: the exemption covers making the booking).
     */
    private function verifiedPhone(StorefrontApiContext $ctx): ?string
    {
        if ($ctx->isKiosk() || ! StoreSettings::bool((int) $ctx->store->id, 'booking_verify_guest_phone')) {
            return null;
        }
        $phone = $ctx->customer !== null ? trim((string) $ctx->customer->phone) : '';
        if ($ctx->customer === null || $ctx->customer->phone_verified_at === null || $phone === '') {
            throw new StorefrontApiException(403, 'phone_verification_required', ___('Verify your phone number to book.'));
        }

        return $phone;
    }

    /** API-7: cancel / reschedule with the rule on need the booking's own verified phone. */
    private function assertOwnPhone(StorefrontApiContext $ctx, Booking $booking): void
    {
        // API-15 exempts the walk-in booking only: changing a booking at a kiosk with the rule on stays refused
        if ($ctx->isKiosk() && StoreSettings::bool((int) $ctx->store->id, 'booking_verify_guest_phone')) {
            throw new StorefrontApiException(403, 'phone_verification_required', ___('Verify your phone number to book.'));
        }
        $phone = $this->verifiedPhone($ctx);
        if ($phone !== null && preg_replace('/\D+/', '', $phone) !== preg_replace('/\D+/', '', (string) $booking->phone)) {
            throw new StorefrontApiException(403, 'phone_verification_required', ___('Verify your phone number to book.'));
        }
    }

    /**
     * Service public ids from a query array, a comma list or a JSON body array.
     *
     * @return list<string>
     */
    private function ids(mixed $raw, string $field, bool $required): array
    {
        $list = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);
        $list = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $list), fn (string $v) => $v !== ''));
        if (($required && $list === []) || count($list) > 4) {
            throw StorefrontApiException::validation([$field => [___('Choose between 1 and 4 services.')]]);
        }

        return $list;
    }

    /** The booking engine has no service options yet: an option id is refused rather than ignored. */
    private function noOptions(mixed $raw): void
    {
        $list = is_string($raw) ? array_filter(explode(',', $raw)) : (array) ($raw ?? []);
        if ($list !== []) {
            throw StorefrontApiException::validation(['option_ids' => [___('This service has no options to choose.')]]);
        }
    }

    /** @param  array<string, mixed>  $q */
    private function staffId(array $q): ?string
    {
        $id = isset($q['staff_id']) ? (string) $q['staff_id'] : null;

        return $id === null || $id === '' || $id === 'any' ? null : $id;
    }

    /** @param  array<string, mixed>  $q */
    private function location(array $q): string
    {
        return in_array((string) ($q['at_home'] ?? ''), ['true', '1'], true) ? 'at_home' : 'venue';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{lat: float|null, lng: float|null}
     */
    private function coords(array $body): array
    {
        $address = (array) ($body['address'] ?? []);

        return [
            'lat' => isset($address['lat']) && is_numeric($address['lat']) ? (float) $address['lat'] : null,
            'lng' => isset($address['lng']) && is_numeric($address['lng']) ? (float) $address['lng'] : null,
        ];
    }

    /** The day strip around a date: from 3 days before (not before today). */
    private function stripStart(Store $store, string $date): string
    {
        $tz = $this->bookings->timezone($store);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = (CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz) ?: $today)->subDays(3);

        return ($start->lt($today) ? $today : $start)->toDateString();
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private function call(StorefrontApiContext $ctx, \Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (BookingRefusal $e) {
            throw $this->view->refusal($ctx->store, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors);
        }

        return $validator->validated();
    }

    /** A replayed create answers its first body with `replayed: true`. */
    private function replayed(Response $response): Response
    {
        if ($response->headers->get('Idempotent-Replayed') !== 'true') {
            return $response;
        }
        $body = json_decode((string) $response->getContent(), true);
        if (is_array($body) && array_key_exists('replayed', $body)) {
            $body['replayed'] = true;
            $response->setContent((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $response;
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body, int $status, StorefrontApiContext $ctx): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store', 'Content-Language' => $ctx->locale], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
