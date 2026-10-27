<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\FawryAdapter;

/** P1-23 part 6: Fawry — see FawryAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class FawryController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return FawryAdapter::class;
    }
}
