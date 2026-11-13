<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use Juwri\Core\Models\Store;
use Juwri\Core\Services\HostedServices\HostedServiceClient;
use Juwri\Core\Services\HostedServices\RelaySignature;

/**
 * Base of the connector clients that go through the juwri Cloud partner relay (`hosted.gate:relay`, store = the
 * connector's store). Each interface method becomes one signed relay call; juwri sends it to the partner with ITS
 * partner account, so no partner credential lives on the installation. A refusal (store past the edition's limit,
 * relay unavailable, partner error) throws {@see RelayRefused} — the connector fails like a failed direct call.
 */
abstract class RelayedClient
{
    /** The partner connection key; each relayed client sets its own. */
    public const PARTNER = '';

    public function __construct(protected readonly HostedServiceClient $client, protected readonly ?int $storeId) {}

    /** @param  list<mixed>  $args */
    protected function call(string $method, array $args): mixed
    {
        $partner = static::PARTNER;
        if (in_array($method, RelaySignature::LOCAL_ONLY, true)) {
            throw new RelayRefused('local_only', "$method is not relayed: the relay uses juwri's partner account.");
        }
        $store = $this->storeId !== null ? Store::query()->find($this->storeId) : null;
        if ($store === null || ! $this->client->connected()) {
            throw new RelayRefused('not_connected', 'The partner relay needs a connected installation and the connector\'s store.');
        }
        $res = $this->client->send('POST', '/api/hosted/v1/relay/'.$partner.'/call', ['method' => $method, 'args' => $args], $store);
        if (! $res->successful()) {
            throw new RelayRefused((string) ($res->json('code') ?? 'relay_failed'), (string) ($res->json('message') ?? 'The partner relay refused the call.'));
        }

        return $res->json('result');
    }
}
