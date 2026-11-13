<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Ordering\Contracts\CheckoutPhoneGate;
use Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController;

final class CheckoutOtpGate implements CheckoutPhoneGate
{
    public function required(int $storeId): bool
    {
        return CheckoutOtpController::gateEnabled($storeId);
    }

    public function normalize(string $phone): string
    {
        return app(Sms::class)->normalizePhone($phone);
    }

    public function verified(string $normalizedPhone, int $storeId): bool
    {
        return CheckoutOtpController::phoneIsVerified($normalizedPhone, $storeId);
    }
}
