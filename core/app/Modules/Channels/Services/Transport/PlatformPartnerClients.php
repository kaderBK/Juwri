<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport;

use Juwri\Modules\Channels\Contracts\PartnerClients;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\UberEats\UberEatsClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\UberEats\UberEatsClientInterface;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationClientFactory;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationClientInterface;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsClientFactory;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsClientInterface;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnClientFactory;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnClientInterface;

/**
 * {@see PartnerClients}: juwri side of the delivery-app partner relay: the partner client juwri calls WITH ITS OWN partner account for a
 * relayed call (the connector factories on juwri Cloud, which use the services.* config — real when configured, else the
 * Fake). Bind another implementation to point the relay at different accounts.
 */
class PlatformPartnerClients implements PartnerClients
{
    /** @var array<string, array{0: class-string, 1: class-string}> partner → [client interface, factory] */
    public const PARTNERS = [
        'uber_eats' => [UberEatsClientInterface::class, UberEatsClientFactory::class],
        'keeta' => [KeetaClientInterface::class, KeetaClientFactory::class],
        'jahez' => [JahezClientInterface::class, JahezClientFactory::class],
        'hungerstation' => [HungerStationClientInterface::class, HungerStationClientFactory::class],
        'foodics' => [FoodicsClientInterface::class, FoodicsClientFactory::class],
        'marn' => [MarnClientInterface::class, MarnClientFactory::class],
    ];

    public function interfaceFor(string $partner): ?string
    {
        return self::PARTNERS[$partner][0] ?? null;
    }

    public function platformClient(string $partner): object
    {
        return self::PARTNERS[$partner][1]::make(null);
    }
}
