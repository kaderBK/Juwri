<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Http\Controllers\Concerns\HandlesShopperPrivacy;
use Symfony\Component\HttpFoundation\Response;

/** P6-91: the storefront account page's "Download my data" / "Delete my account" (signed-in customer of THIS store). */
class ShopperPrivacyController extends Controller
{
    use HandlesShopperPrivacy;

    public function code(Request $request, string $store): JsonResponse
    {
        return $this->privacyCode($this->customer($store));
    }

    public function export(Request $request, string $store): Response
    {
        return $this->privacyExport($request, $this->customer($store), true);
    }

    public function destroy(Request $request, string $store): JsonResponse
    {
        $response = $this->privacyDelete($request, $this->customer($store), true);
        if ($response->getData(true)['success'] ?? false) {
            Auth::guard('customer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $response;
    }

    private function customer(string $store): Customer
    {
        abort_unless(Auth::guard('customer')->check(), 401);
        $store = Store::idOrSlug($store)->firstOrFail();
        $customer = Auth::guard('customer')->user();
        abort_unless($customer instanceof Customer && (int) $customer->store_id === (int) $store->id, 403);

        return $customer;
    }
}
