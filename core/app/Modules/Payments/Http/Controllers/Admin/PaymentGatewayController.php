<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Support\DeferredGateways;
use Juwri\Modules\Payments\Http\Requests\Admin\PaymentGatewayRequest;
use Juwri\Modules\Payments\Models\PaylinkOnboarding;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Support\GatewaySettings;

/**
 * Admin › Payment gateways (platform billing, J22): switch gateways on/off,
 * rename them and keep their credentials. The gateway integrations
 * themselves live under Controllers/User/PaymentMethods.
 */
class PaymentGatewayController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->authorizeResource(PaymentGateway::class, 'gateway');
    }

    public function index(): View
    {
        $gateways = PaymentGateway::all()
            ->reject(fn (PaymentGateway $gateway) => DeferredGateways::isHidden((string) $gateway->payment_folder));

        return view('admin.billing.gateways.index', ['gateways' => $gateways]);
    }

    /**
     * G10: Paylink partner onboarding audit — every attempt with status +
     * per-reseller grouping. Read-only superadmin view.
     */
    public function partnerOnboardings(): View
    {
        $this->authorize('viewAny', PaymentGateway::class);

        $onboardings = PaylinkOnboarding::orderByDesc('updated_at')->limit(500)->get();
        $counts = $onboardings->groupBy('status')->map->count();

        return view('admin.billing.gateways.paylink-onboardings', compact('onboardings', 'counts'));
    }

    public function edit(PaymentGateway $gateway): View
    {
        abort_if(DeferredGateways::isHidden((string) $gateway->payment_folder), 404);

        return view('admin.billing.gateways.edit', ['gateway' => $gateway]);
    }

    /**
     * Save the title / on-off switch and exactly the settings this gateway's
     * form owns. Secrets are encrypted at rest and a masked (untouched) secret
     * keeps the stored value — both handled by PlatformSettings::set.
     */
    public function update(PaymentGatewayRequest $request, PaymentGateway $gateway): JsonResponse
    {
        abort_if(DeferredGateways::isHidden((string) $gateway->payment_folder), 404);

        $gateway->update([
            'payment_install' => $request->boolean('active'),
            'payment_title' => $request->validated('title'),
        ]);

        foreach (GatewaySettings::keysFor($gateway->payment_folder) as $key) {
            PlatformSettings::set($key, $request->input($key));
        }

        return $this->panelDone(___('Updated Successfully'));
    }
}
