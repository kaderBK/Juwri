<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport;

use Closure;
use Illuminate\Http\Request;
use Juwri\Modules\Channels\Contracts\PartnerTransport;
use UnexpectedValueException;

/**
 * P1-13 13c-R: the one place the connectors call the bound {@see PartnerTransport}. A connector key that is not a
 * partner-credential connector (Square, Clover, custom, the India aggregators) always takes the direct path.
 */
final class PartnerTransports
{
    public static function routes(string $partner): bool
    {
        return in_array($partner, PartnerTransport::PARTNERS, true);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $interface
     * @param  Closure(): T  $direct
     * @return T
     */
    public static function client(string $partner, ?int $storeId, string $interface, Closure $direct): object
    {
        if (! self::routes($partner)) {
            return $direct();
        }
        $client = app(PartnerTransport::class)->client($partner, $storeId, $interface, $direct);
        if (! $client instanceof $interface) {
            throw new UnexpectedValueException('The partner transport returned a '.get_debug_type($client)." for $partner, not a $interface.");
        }

        return $client;
    }

    /** @param  Closure(Request): bool  $direct */
    public static function verifyInbound(string $partner, Request $request, Closure $direct): bool
    {
        return self::routes($partner)
            ? app(PartnerTransport::class)->verifyInbound($partner, $request, $direct)
            : $direct($request);
    }
}
