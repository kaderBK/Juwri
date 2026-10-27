<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\MoyasarAdapter;

/** Phase 4 item 18: Moyasar (KSA) — see MoyasarAdapter. */
class MoyasarController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return MoyasarAdapter::class;
    }
}
