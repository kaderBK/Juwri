<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Contracts;

use Closure;
use Illuminate\Http\Request;
use Juwri\Modules\Channels\Services\Transport\DirectPartnerTransport;

/**
 * P1-13 13c-R (owner decision A): how the partner-credential connectors reach their partner — orders, menus,
 * availability and statuses out (through the connector's client), webhooks in. Partner-credential connectors are the
 * ones whose API access is a juwri partner account: {@see self::PARTNERS}. Merchant-own-credential connectors
 * (Square, Clover, the custom webhook) never go through it.
 *
 * Community default ({@see DirectPartnerTransport}): direct — the
 * connector's own client with the config / stored credentials and the partner's own webhook signature, exactly as
 * before. A juwri Cloud partner relay (later Cloud item) binds another implementation in the container: it returns
 * clients of the same interface that carry the calls through the relay, and checks the relay's signature on webhooks
 * the relay forwards.
 */
interface PartnerTransport
{
    /** Partner-credential connectors (connection keys) that go through the transport. */
    public const PARTNERS = ['hungerstation', 'jahez', 'keeta', 'uber_eats', 'deliverect', 'foodics', 'marn'];

    /**
     * The connector client for one store: what sends orders, menus, availability and statuses to the partner.
     * $direct builds the client the connector uses without a relay (real client when configured, else its Fake).
     *
     * @template T of object
     *
     * @param  class-string<T>  $interface  the connector's client interface; the result must implement it
     * @param  Closure(): T  $direct
     * @return T
     */
    public function client(string $partner, ?int $storeId, string $interface, Closure $direct): object;

    /**
     * Is this inbound webhook genuine? $direct is the partner's own check (signature / URL token) for this request.
     *
     * @param  Closure(Request): bool  $direct
     */
    public function verifyInbound(string $partner, Request $request, Closure $direct): bool;
}
