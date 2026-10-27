<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\PaytmAdapter;

/** Paytm — see PaytmAdapter. */
class PaytmController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return PaytmAdapter::class;
    }
}
