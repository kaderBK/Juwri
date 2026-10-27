<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\MollieAdapter;

/** Mollie — see MollieAdapter. */
class MollieController extends GatewayAdapterController
{
    protected static function adapterClass(): string
    {
        return MollieAdapter::class;
    }

    /** The webhook URL sent with each payment (null = none); see MollieAdapter::webhookUrl(). */
    protected static function webhookUrl(): ?string
    {
        return app(MollieAdapter::class)->webhookUrl();
    }
}
