<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\StripeAdapter;

/** Stripe Checkout — see StripeAdapter. */
class StripeController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return StripeAdapter::class;
    }
}
