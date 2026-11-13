<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport;

use Closure;
use Illuminate\Http\Request;
use Juwri\Modules\Channels\Contracts\PartnerTransport;

/**
 * P1-13 13c-R: the Community {@see PartnerTransport} — every partner connector talks to its partner directly with its
 * config / stored credentials and checks the partner's own webhook signature (the behaviour before the extension
 * point existed: same clients, byte-identical requests).
 */
final class DirectPartnerTransport implements PartnerTransport
{
    public function client(string $partner, ?int $storeId, string $interface, Closure $direct): object
    {
        return $direct();
    }

    public function verifyInbound(string $partner, Request $request, Closure $direct): bool
    {
        return $direct($request);
    }
}
