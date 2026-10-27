<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Requests\Admin;

/** Billing-interval scope of a coupon; anything unrecognised means "any interval". */
trait NormalisesCouponInterval
{
    public function billingInterval(): string
    {
        $interval = $this->input('applies_interval');

        return in_array($interval, ['monthly', 'yearly', 'lifetime'], true) ? $interval : 'any';
    }
}
