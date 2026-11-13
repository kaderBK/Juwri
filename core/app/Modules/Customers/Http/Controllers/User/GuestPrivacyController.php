<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Services\Privacy\ShopperPrivacyService;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P6-209: the owner handles a GDPR / PDPL request from a guest (orders without an account) of the selected store.
 * The phone is proven first: a one-time code is sent to it (store's own SMS account, privacy scope) and the guest
 * reads it to the owner. Only then can the owner download or anonymise that phone's guest orders, for 15 minutes.
 */
class GuestPrivacyController extends Controller
{
    private const PROOF_TTL = 900;

    public const CONFIRM_WORD = 'DELETE';

    public function index(Request $request): View
    {
        $store = $this->store();
        $proven = $this->provenPhone($request, $store);
        $service = app(ShopperPrivacyService::class);

        return view('owner.customers.guest-privacy', [
            'store' => $store,
            'phone' => $proven,
            'orderCount' => $proven !== null ? count($service->guestOrderIds((int) $store->id, $proven)) : null,
            'blocker' => $proven !== null ? $service->guestBlocker((int) $store->id, $proven) : null,
            'confirmWord' => self::CONFIRM_WORD,
        ]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $store = $this->store();
        $phone = ShopperPrivacyService::normalisePhone((string) $request->input('phone', ''));
        if (strlen(ltrim($phone, '+')) < 6) {
            return back()->withErrors(['phone' => ___('Enter a valid phone number.')]);
        }
        [$ok, $message] = app(SmsOtp::class)->request($phone, OtpScope::privacy((int) $store->id));
        session()->put($this->pendingKey($store), $phone);

        return back()->with($ok ? 'success' : 'error', $message)->withInput(['phone' => $phone]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $store = $this->store();
        $phone = (string) session()->get($this->pendingKey($store), '');
        $code = trim((string) $request->input('code', ''));
        if ($phone === '' || $code === '' || ! app(SmsOtp::class)->verify($phone, $code, OtpScope::privacy((int) $store->id))) {
            return back()->withErrors(['code' => ___('The code is wrong or has expired.')]);
        }
        session()->forget($this->pendingKey($store));
        session()->put($this->proofKey($store), ['phone' => $phone, 'until' => now()->addSeconds(self::PROOF_TTL)->getTimestamp()]);

        return redirect()->route('customers.guestPrivacy')->with('success', ___('Phone verified.'));
    }

    public function export(Request $request): Response
    {
        $store = $this->store();
        $phone = $this->provenPhone($request, $store);
        abort_if($phone === null, 403);
        $key = 'guest-export:'.$store->id.':'.$phone;
        if (RateLimiter::tooManyAttempts($key, ShopperPrivacyService::EXPORTS_PER_DAY)) {
            abort(429);
        }
        RateLimiter::hit($key, 86400);
        $service = app(ShopperPrivacyService::class);
        $service->logGuestExport((int) $store->id, $phone, (int) auth()->id());

        return new StreamedResponse(function () use ($service, $store, $phone): void {
            $out = fopen('php://output', 'wb');
            $service->writeGuestJson((int) $store->id, $phone, $out);
            fclose($out);
        }, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="guest-data-store-'.$store->id.'-'.now()->format('Ymd').'.json"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function erase(Request $request): RedirectResponse
    {
        $store = $this->store();
        $phone = $this->provenPhone($request, $store);
        abort_if($phone === null, 403);
        if (trim((string) $request->input('confirm', '')) !== self::CONFIRM_WORD) {
            return back()->withErrors(['confirm' => ___('Type :word to confirm.', ['word' => self::CONFIRM_WORD])]);
        }
        $service = app(ShopperPrivacyService::class);
        if (($blocker = $service->guestBlocker((int) $store->id, $phone)) !== null) {
            return back()->with('error', $blocker);
        }
        $report = $service->eraseGuest((int) $store->id, $phone, (int) auth()->id());
        session()->forget($this->proofKey($store));

        return redirect()->route('customers.guestPrivacy')
            ->with('success', ___(':count guest orders anonymised.', ['count' => (int) ($report['guest_orders'] ?? 0)]));
    }

    private function store(): Store
    {
        $store = dashboard_current_store();
        abort_if(staff_session() !== null, 403); // the owner handles data-subject requests, not staff
        abort_unless($store && in_array((int) $store->id, array_map('intval', dashboard_store_ids()), true), 403);

        return $store;
    }

    private function provenPhone(Request $request, Store $store): ?string
    {
        $proof = session()->get($this->proofKey($store));
        if (! is_array($proof) || (int) ($proof['until'] ?? 0) < now()->getTimestamp() || ($proof['phone'] ?? '') === '') {
            return null;
        }

        return (string) $proof['phone'];
    }

    private function pendingKey(Store $store): string
    {
        return 'guest_privacy.pending.'.$store->id;
    }

    private function proofKey(Store $store): string
    {
        return 'guest_privacy.proof.'.$store->id;
    }
}
