<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta\Contracts;

/**
 * TAX-RUN E-P2: ETA (Egypt) e-Receipt API surface. Authenticate the POS device
 * for an OAuth-style token, then submit receipts (batchable). Async validation
 * results arrive via a notification callback the app exposes. Implemented by the
 * HTTP client (preprod/prod) and FakeEtaClient (fixtures).
 */
interface EtaClient
{
    /**
     * @param  array{rin:string,clientId:string,secret:string}  $creds
     * @return array{token:string,expiresIn:int}
     */
    public function authenticatePos(array $creds): array;

    /**
     * Submit one or more receipts.
     *
     * @return array{status:string,acceptedDocuments:array,rejectedDocuments:array,response:array}
     */
    public function submitReceipts(string $token, array $receipts): array;

    /** Anonymous public lookup URL for a submitted receipt (printed as a QR). */
    public function lookupUrl(string $uuid): string;
}
