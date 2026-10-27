<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\PaylinkOnboarding;
use Juwri\Modules\Payments\Services\PaylinkPartner;

/**
 * PAYMENTS-RUN G10 — owner "Get paid with Paylink" onboarding wizard.
 * Gated by `feature:paylink_partner` (superadmin default OFF). Tenant-scoped.
 */
class PaylinkPartnerController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $onboarding = PaylinkOnboarding::where('store_id', $rid)->first();

        return view('owner.payments.paylink-partner', [
            'activeTheme' => active_theme(),
            'rid' => $rid,
            'onboarding' => $onboarding,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function submit(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        $validator = Validator::make($request->all(), [
            'cr_number' => 'required|string|max:50',
            'owner_id_number' => 'required|string|max:50',
            'iban' => 'required|string|max:40',
        ]);
        if ($validator->fails()) {
            app_alert_error($validator->errors()->first());

            return back()->withInput();
        }

        PaylinkPartner::submitRegistration($rid, [
            'cr_number' => $request->get('cr_number'),
            'owner_id_number' => $request->get('owner_id_number'),
            'iban' => $request->get('iban'),
            'email' => $request->get('email'),
        ]);

        app_alert_success(___('Your Paylink onboarding was submitted. Complete Nafath verification to activate.'));

        return back();
    }

    public function disconnect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        PaylinkOnboarding::where('store_id', $rid)->delete();
        StoreOption::updateStoreOption($rid, 'restaurant_paylink_install', '0');

        app_alert_success(___('Paylink onboarding removed.'));

        return back();
    }

    /**
     * Activation webhook (unsigned — re-verify server-side by re-fetching keys).
     * Tenant resolved from OUR stored provider_merchant_id, never the payload.
     * NOT feature-gated: an in-flight onboarding must be able to activate even if
     * the superadmin toggles the feature mid-flight; but it only ever touches
     * restaurants that already submitted.
     */
    public function webhook(Request $request)
    {
        $merchantId = $request->input('merchantId') ?: $request->input('merchant_id');
        if (empty($merchantId)) {
            return response('missing merchant id', 200);
        }

        PaylinkPartner::handleActivation((string) $merchantId);

        return response('ok', 200);
    }
}
