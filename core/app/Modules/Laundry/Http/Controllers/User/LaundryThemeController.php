<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Http\Controllers\User;

use App\Services\Verticals\VerticalRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Laundry\Contracts\LaundryPageRenderer;
use Juwri\Modules\Laundry\Exceptions\LaundryHoldException;
use Juwri\Modules\Laundry\Models\LaundryHold;
use Juwri\Modules\Laundry\Services\Laundry\LaundryHolds;
use Juwri\Modules\Laundry\Services\Laundry\LaundryPages;
use Juwri\Modules\Laundry\Services\Laundry\ServiceBookingService;
use Juwri\Modules\Ordering\Contracts\ScheduledSlots;
use Juwri\Modules\Verticals\Contracts\GoodsCartQuote;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * P3-11: the laundry booking on the `book` theme — choose the services (the theme's own list), choose the pickup time and, when
 * the laundry brings the order back, the delivery time, hold both with ONE hold (API-23), confirm and place. The page-building
 * is the theme's (LaundryPageRenderer); the writes are the same services the plain booking page uses (ServiceBookingService,
 * LaundryHolds). Holds belong to the browser session that made them; their token lives only in the session.
 */
class LaundryThemeController extends Controller
{
    private const MODES = ['pickup_slot', 'delivery'];

    public function __construct(private ServiceBookingService $booking, private LaundryHolds $holds, private LaundryPages $pages, private ScheduledSlots $settings) {}

    /** Replace the cart with the chosen services, then go and choose the times. */
    public function lines(string $slug, Request $request): RedirectResponse|JsonResponse
    {
        $store = $this->store($slug);
        $data = $request->validate([
            'lines' => ['required', 'array', 'max:40'],
            'lines.*' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'type' => ['nullable', 'string', 'max:32'],
        ]);
        try {
            $this->booking->replaceLines($store, $this->booking->cartToken($request), $data['lines']);
        } catch (ValidationException $e) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422)
                : redirect()->route('publicView', ['slug' => $store->slug])->withErrors($e->errors());
        }
        $mode = $this->mode($store, $data['type'] ?? null);

        return $request->wantsJson()
            ? response()->json(['success' => true, 'next' => route('laundry.book.schedule', ['slug' => $store->slug, 'type' => $mode], false)])
            : redirect()->route('laundry.book.schedule', ['slug' => $store->slug, 'type' => $mode]);
    }

    /** Step 2: the pickup time (and, for a delivery order, the delivery time). */
    public function schedule(string $slug, Request $request, GoodsCartQuote $quotes): BaseResponse|RedirectResponse
    {
        $store = $this->store($slug);
        $mode = $this->mode($store, $request->query('type'));
        $quote = $quotes->quote($store, $request, $mode, null, true);
        if ($quote === null || ! $this->renderer()?->renders($store)) {
            return redirect()->route($quote === null ? 'publicView' : 'laundry.book.show', ['slug' => $store->slug])
                ->withErrors($quote === null ? ['lines' => ___('Choose at least one service.')] : []);
        }

        $data = [
            'store' => $store,
            'mode' => $mode,
            'modes' => $this->modes($store),
            'schedule' => $this->pages->schedule($store, $mode, $this->str($request->query('date')), $this->str($request->query('pickup')), $this->str($request->query('return_date'))),
            'pickup' => $this->str($request->query('pickup')),
            'return' => $this->str($request->query('return')),
            'quote' => $quote,
            'turnaround' => $this->holds->turnaroundHours((int) $store->id),
        ];

        return $this->renderer()->render($store, 'schedule', $data) ?? redirect()->route('laundry.book.show', ['slug' => $store->slug]);
    }

    /** One hold for the pickup slot and (delivery) the delivery slot. */
    public function hold(string $slug, Request $request): RedirectResponse|JsonResponse
    {
        $store = $this->store($slug);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::MODES)],
            'pickup' => ['required', 'string', 'max:20'],
            'return' => ['nullable', 'string', 'max:20'],
        ]);
        try {
            $made = $this->holds->hold($store, $data['type'], $data['pickup'], $data['return'] ?? null, $this->holderKey($request));
        } catch (LaundryHoldException $e) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'error_code' => $e->errorCode, 'message' => $e->getMessage()], $e->status)
                : back()->withErrors(['hold' => $e->getMessage()])->withInput();
        }
        $hold = $made['hold'];
        $request->session()->put('laundry.holds.'.$hold->public_id, $made['token']);

        return $request->wantsJson()
            ? response()->json(['success' => true, 'hold' => $this->hold_json($hold), 'next' => route('laundry.book.confirm', ['slug' => $store->slug, 'hold' => $hold->public_id], false)], 201)
            : redirect()->route('laundry.book.confirm', ['slug' => $store->slug, 'hold' => $hold->public_id]);
    }

    /** Give the held times back (the customer went back to change them). */
    public function release(string $slug, string $hold, Request $request): RedirectResponse|JsonResponse
    {
        $store = $this->store($slug);
        $row = $this->sessionHold($store, $hold, $request);
        if ($row !== null) {
            $this->holds->release($row);
            $request->session()->forget('laundry.holds.'.$row->public_id);
        }

        return $request->wantsJson()
            ? response()->json(['success' => true])
            : redirect()->route('laundry.book.schedule', ['slug' => $store->slug, 'type' => $row->mode ?? 'pickup_slot']);
    }

    /** Step 3: your details, the summary that sums to the total, place. */
    public function confirm(string $slug, string $hold, Request $request, GoodsCartQuote $quotes): BaseResponse|RedirectResponse
    {
        $store = $this->store($slug);
        $row = $this->sessionHold($store, $hold, $request);
        if ($row === null || ! $row->isLive() || ! $this->renderer()?->renders($store)) {
            return redirect()->route('publicView', ['slug' => $store->slug])->withErrors(['hold' => LaundryHoldException::holdExpired()->getMessage()]);
        }
        $quote = $quotes->quote($store, $request, $row->mode, null, true);
        if ($quote === null) {
            return redirect()->route('publicView', ['slug' => $store->slug])->withErrors(['lines' => ___('Choose at least one service.')]);
        }

        $tz = $this->tz($store);
        $data = [
            'store' => $store,
            'mode' => $row->mode,
            'hold' => $row,
            'pickupAt' => $row->pickup_at->copy()->setTimezone($tz),
            'returnAt' => $row->return_at?->copy()->setTimezone($tz),
            'expiresAt' => $row->expires_at->copy()->setTimezone($tz)->format('H:i'),
            'quote' => $quote,
            'clientKey' => (string) Str::uuid7(),
            'back' => route('laundry.book.schedule', ['slug' => $store->slug, 'type' => $row->mode], false),
        ];

        return $this->renderer()->render($store, 'details', $data) ?? redirect()->route('laundry.book.show', ['slug' => $store->slug]);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    private function renderer(): ?LaundryPageRenderer
    {
        return app()->bound(LaundryPageRenderer::class) ? app(LaundryPageRenderer::class) : null;
    }

    private function store(string $slug): Store
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        abort_unless(app(VerticalRegistry::class)->has($store->store_type ?? null, 'service'), 404);

        return $store;
    }

    /** @return list<string> the slot modes this store offers (pickup at the store, collect-and-deliver) */
    private function modes(Store $store): array
    {
        return array_values(array_intersect(self::MODES, $this->settings->offeredModes($store)));
    }

    private function mode(Store $store, mixed $asked): string
    {
        $modes = $this->modes($store);
        $asked = is_string($asked) ? $asked : '';

        return in_array($asked, $modes, true) ? $asked : ($modes[0] ?? 'pickup_slot');
    }

    private function sessionHold(Store $store, string $publicId, Request $request): ?LaundryHold
    {
        $token = (string) $request->session()->get('laundry.holds.'.$publicId, '');
        if ($token === '' || ! Str::isUuid($publicId)) {
            return null;
        }
        $hold = LaundryHold::query()->where('store_id', $store->id)->where('public_id', $publicId)->first();

        return $hold instanceof LaundryHold && $hold->tokenMatches($token) ? $hold : null;
    }

    private function holderKey(Request $request): string
    {
        return hash('sha256', $request->session()->getId().'|'.$request->ip());
    }

    private function tz(Store $store): string
    {
        return (string) store_tz((int) $store->id);
    }

    private function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** @return array<string, mixed> */
    private function hold_json(LaundryHold $hold): array
    {
        return [
            'id' => (string) $hold->public_id,
            'type' => (string) $hold->mode,
            'pickup_at' => $hold->pickup_at->toIso8601String(),
            'return_at' => $hold->return_at?->toIso8601String(),
            'expires_at' => $hold->expires_at->toIso8601String(),
        ];
    }
}
