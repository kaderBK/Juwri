<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

use Illuminate\Support\Carbon;

/**
 * P6-126: read-only facts about a ZATCA binarySecurityToken (base64 of the base64 DER body of an X.509 certificate),
 * for modules that hold one (the Pos offline tills) without reaching into the Fiscal ZATCA internals.
 */
interface ZatcaCertificates
{
    /** Not-after date of the certificate, or null when the token is not a parseable certificate. */
    public function expiry(string $token): ?Carbon;
}
