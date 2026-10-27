<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Illuminate\Support\Carbon;
use Juwri\Modules\Fiscal\Contracts\ZatcaCertificates;

/** P6-126: the ZatcaCertificates contract over the ZatcaCertificate helpers. */
final class ZatcaCertificateFacts implements ZatcaCertificates
{
    public function expiry(string $token): ?Carbon
    {
        return ZatcaCertificate::expiry($token);
    }
}
