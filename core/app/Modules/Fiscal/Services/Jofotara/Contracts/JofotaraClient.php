<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Jofotara\Contracts;

/**
 * TAX-RUN J-P1/J-P2: JoFotara (Jordan ISTD) REST surface. Auth is via the
 * taxpayer's Client ID + Secret Key + Activity Number. Submission is clearance-
 * style: the ISTD RETURNS the QR, so the customer receipt is final only after a
 * successful submit.
 */
interface JofotaraClient
{
    /**
     * Submit (clear) an invoice. On success returns the ISTD QR + registration
     * number the receipt must carry.
     *
     * @param  array{clientId:string,secret:string,activityNumber:string}  $creds
     * @return array{status:string,qr?:string,registrationNumber?:string,response:array}
     */
    public function submit(array $creds, array $document): array;
}
