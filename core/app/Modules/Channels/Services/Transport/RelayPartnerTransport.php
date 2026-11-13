<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Services\HostedServices\HostedServiceClient;
use Juwri\Core\Services\HostedServices\RelaySignature;
use Juwri\Modules\Channels\Contracts\PartnerTransport;
use Juwri\Modules\Channels\Services\Transport\Relay\RelayedClient;
use Juwri\Modules\Channels\Services\Transport\Relay\RelayedFoodicsClient;
use Juwri\Modules\Channels\Services\Transport\Relay\RelayedHungerStationClient;
use Juwri\Modules\Channels\Services\Transport\Relay\RelayedJahezClient;
use Juwri\Modules\Channels\Services\Transport\Relay\RelayedKeetaClient;
use Juwri\Modules\Channels\Services\Transport\Relay\RelayedMarnClient;
use Juwri\Modules\Channels\Services\Transport\Relay\RelayedUberEatsClient;

/**
 * The juwri Cloud partner relay as the {@see PartnerTransport} of a self-hosted installation
 * (CHANNELS_PARTNER_TRANSPORT=juwri_relay, after `juwri:services:connect`): partner calls go through
 * `hosted.gate:relay` for the connector's store, and webhooks the relay forwards are checked with the relay's
 * signature (HMAC with this installation's service secret). A webhook without the relay's headers takes the
 * partner's own check, exactly as in Community.
 */
final class RelayPartnerTransport implements PartnerTransport
{
    /** @var array<string, class-string<RelayedClient>> */
    public const CLIENTS = [
        'uber_eats' => RelayedUberEatsClient::class, 'keeta' => RelayedKeetaClient::class, 'jahez' => RelayedJahezClient::class,
        'hungerstation' => RelayedHungerStationClient::class, 'foodics' => RelayedFoodicsClient::class, 'marn' => RelayedMarnClient::class,
    ];

    public function __construct(private readonly HostedServiceClient $client) {}

    public function client(string $partner, ?int $storeId, string $interface, Closure $direct): object
    {
        $class = self::CLIENTS[$partner] ?? null;

        return $class !== null && is_a($class, $interface, true) ? new $class($this->client, $storeId) : $direct();
    }

    public function verifyInbound(string $partner, Request $request, Closure $direct): bool
    {
        if (! $request->hasHeader(RelaySignature::SIGNATURE)) {
            return $direct($request);
        }

        return $this->client->verifyRelayed($partner, (string) $request->header(RelaySignature::TIMESTAMP), (string) $request->getContent(), (string) $request->header(RelaySignature::SIGNATURE));
    }
}
