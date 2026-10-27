<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;

/**
 * rev/multi-vertical B1 — store settings "Fulfillment" tab.
 *
 * A non-food store owner toggles which of its vertical's allowed fulfillment
 * modes (FulfillmentRegistry->modesFor(store_type)) are currently offered.
 * Restaurants never reach this controller: the page-action link that points
 * here is gated on !isFood() (see the edit.blade.php page_actions addition),
 * and modesFor('restaurant') never returns ship/pickup_slot/appointment
 * anyway, so their existing on-table/takeaway/delivery toggles are untouched.
 */
class FulfillmentSettingsController extends Controller
{
    use ResolvesCurrentStore;

    public function edit(
        Request $request,
        FulfillmentSettingsService $settings,
        FulfillmentRegistry $registry,
        VerticalVocab $vocab
    ): View {
        $store = $this->currentRestaurant($request);

        return view('owner.dispatch.fulfillment', [
            'store' => $store,
            'availableModes' => $settings->availableModes($store),
            'enabledModes' => $settings->enabledModes($store),
            'registry' => $registry,
            'vocab' => $vocab,
        ]);
    }

    public function update(
        Request $request,
        FulfillmentSettingsService $settings
    ): RedirectResponse {
        $store = $this->currentRestaurant($request);

        $settings->update($store, (array) $request->input('modes', []));

        return back()->with('success', ___('Fulfillment settings saved.'));
    }
}
