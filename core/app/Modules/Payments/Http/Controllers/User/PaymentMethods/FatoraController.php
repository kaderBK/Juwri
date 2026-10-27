<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\FatoraAdapter;

/** P1-23 part 6: Fatora — see FatoraAdapter (same routes and callback URLs as the hand-written controller it replaced). */
class FatoraController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return FatoraAdapter::class;
    }
}
