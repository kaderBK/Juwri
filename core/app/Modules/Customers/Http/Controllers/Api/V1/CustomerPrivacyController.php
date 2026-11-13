<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\Api\V1;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Customers\Http\Controllers\Concerns\HandlesShopperPrivacy;
use Symfony\Component\HttpFoundation\Response;

/**
 * P6-91: the customer app's data export and account deletion — same rules as the storefront (password or phone
 * code, 3 exports a day, typed confirmation). Deleting revokes every app token of the customer (rows deleted).
 */
class CustomerPrivacyController extends Controller
{
    use HandlesShopperPrivacy;

    public function code(): JsonResponse
    {
        return $this->privacyCode($this->customer());
    }

    public function export(Request $request): Response
    {
        return $this->privacyExport($request, $this->customer(), false);
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->privacyDelete($request, $this->customer(), false);
    }

    private function customer(): Customer
    {
        return app('api.customer');
    }
}
