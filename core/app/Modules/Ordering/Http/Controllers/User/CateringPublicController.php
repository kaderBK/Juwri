<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\CateringRequest;
use App\Services\Catering\CateringService;
use App\Services\FeatureAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\CustomerGateways;

/**
 * GAPS-RUN-2 B8-P2: PUBLIC catering request submission (from the storefront form).
 * Creates a request only — never a direct order. Gated by catering (kill-switch +
 * owner catering_enabled) inside the service.
 */
class CateringPublicController extends Controller
{
    public function store(Request $request, CateringService $catering)
    {
        $store = $this->resolveRestaurant($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'event_at' => ['required', 'date'],
            'headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $catering->createRequest($store->id, $data);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('catering_success', ___('Thanks! We received your catering request and will be in touch.'));
    }

    private function resolveRestaurant(Request $request): Store
    {
        $key = $request->route('slug');

        return Store::query()->idOrSlug($key)->firstOrFail();
    }

    /** Gate: catering entitlement + the store's catering toggle (mirrors createRequest). */
    private function assertCateringAvailable(Store $store): void
    {
        abort_unless(
            FeatureAccess::storeAllows($store->id, 'catering') && store_options($store->id, 'catering_enabled'),
            404
        );
    }

    /**
     * Customer-facing catering deposit step (anonymous). Reached via the link the owner
     * sends after accepting; capability is the request's random token.
     */
    public function depositPay(Request $request, string $slug, string $token, CateringService $catering): View|RedirectResponse
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        $this->assertCateringAvailable($store);

        $req = CateringRequest::where('store_id', $store->id)->where('token', $token)->firstOrFail();
        if ($req->deposit_status !== 'pending' || ! $catering->depositEnabled($store->id)) {
            return redirect()->route('publicView', $store->slug);
        }

        return view('catering.deposit-pay', [
            'store' => $store,
            'req' => $req,
            'amount' => money_round((float) ($req->deposit_amount ?: 0), money_decimals($req->currency ?: store_currency($store->id))),
            'currency' => $req->currency ?: store_currency($store->id),
            // P1-23: the store's own gateways — the deposit is paid to the store, never to the platform.
            'gateways' => app(CustomerGateways::class)->forStore((int) $store->id),
        ]);
    }

    /**
     * Open the catering deposit Transaction and hand off to the chosen installed gateway
     * (the GiftCardController pattern). On confirmation the shared CheckoutController choke
     * point calls CateringService::markDepositPaid().
     */
    public function depositCharge(Request $request, string $slug, string $token, CateringService $catering): View|RedirectResponse
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        $this->assertCateringAvailable($store);

        $req = CateringRequest::where('store_id', $store->id)->where('token', $token)->firstOrFail();
        if ($req->deposit_status !== 'pending') {
            return redirect()->route('publicView', $store->slug);
        }

        $data = $request->validate(['payment_method' => ['required']]);
        $folder = app(CustomerGateways::class)->resolve((int) $store->id, $data['payment_method']);
        $gateway = $folder !== null ? (object) ['payment_folder' => $folder] : null;
        if (! $gateway) {
            return back()->withErrors(['payment_method' => ___('Please choose a valid payment method.')]);
        }

        $transaction = $catering->initiateDeposit($req, $gateway->payment_folder);
        if (! $transaction) {
            return back()->withErrors(['payment_method' => ___('Deposit is not available.')]);
        }

        $paymentController = 'App\\Http\\Controllers\\User\\PaymentMethods\\'
            .str_replace(' ', '', ucwords(str_replace('_', ' ', $gateway->payment_folder)))
            .'Controller';

        return $paymentController::pay($transaction);
    }
}
