<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\User;

use App\Services\Analytics\ForecastService;
use App\Services\FeatureSpotlight;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Delivery\Services\Analytics\DeliveryInsightsService;

/**
 * GAPS-RUN-2 B5-P1: delivery insights tab (heat grid, top areas, courier GPS).
 * Behind the demand_forecast kill-switch. Tenant scoped.
 */
class DeliveryInsightsController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request, DeliveryInsightsService $service, ForecastService $forecast)
    {
        $rid = $this->currentRestaurantId($request);

        /* WI-A3 (upsell-visibility): rendered blurred for an unentitled owner
           instead of redirecting them to a page that only describes it.

           Two things are withheld, because blur hides nothing from the page
           source: the courier scatter is up to 500 raw lat/lng points — in
           practice the coordinates of deliveries — and the "top areas" list is
           a set of address tokens, which for a store with few orders can name a
           single street. The heat grid and the forecast are day/hour counts
           with no identity in them at all, so they carry the preview. */
        $preview = FeatureSpotlight::isPreview('demand_forecast', $request->user());

        // WI-B1: same reason as CrmController::rfm — this route is gated in the
        // controller, so the layout has no middleware to learn the key from.
        if (! $preview) {
            view()->share('pageFeatureKey', 'demand_forecast');
        }

        return view('owner.dispatch.insights', [
            'rid' => $rid,
            'heat' => $service->heatGrid($rid),
            'topAreas' => $preview ? [] : $service->topAreas($rid),
            'scatter' => $preview ? [] : $service->courierScatter($rid),
            'forecast' => $forecast->cached($rid),
            'preview' => $preview,
            'previewKey' => 'demand_forecast',
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }
}
