<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\GocardlessAdapter;

/**
 * P1-23 part 6: GoCardless — see GocardlessAdapter (same routes and callback URLs as the hand-written controller it
 * replaced). Platform purchases only: never offered to a store's customers (StoreCustomerGateways::PLATFORM_ONLY).
 */
class GocardlessController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return GocardlessAdapter::class;
    }
}
