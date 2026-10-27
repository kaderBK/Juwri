<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Http\Controllers\Api\V1;

use App\Services\SalesAnalyticsService;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Api\V1\Concerns\OwnerScoped;
use Juwri\Core\Http\Controllers\Controller;

/** Owner-token sales analytics for the merchant app. */
class AnalyticsController extends Controller
{
    use OwnerScoped;

    public function summary(Request $request, int $storeId)
    {
        $this->ownedRestaurant($request, $storeId);

        return response()->json(
            SalesAnalyticsService::summary($storeId, $request->query('from'), $request->query('to'))
        );
    }
}
