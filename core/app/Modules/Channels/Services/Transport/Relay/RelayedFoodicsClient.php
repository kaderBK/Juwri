<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsClientInterface;

/** FoodicsClientInterface through the juwri Cloud partner relay (generated shape: one relay call per method). */
final class RelayedFoodicsClient extends RelayedClient implements FoodicsClientInterface
{
    public const PARTNER = 'foodics';

    /**
     * GET /whoami — cheap auth check for testConnection().
     *
     * @return array<mixed>
     */
    public function whoami(): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * GET /branches — includes receives_online_orders + opening hours.
     *
     * @return array<mixed>
     */
    public function listBranches(): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * GET /products?include=category,modifiers,branches — one page.
     *
     * @return array<mixed>
     */
    public function listProducts(int $page = 1): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * GET /modifiers (modifier groups + options).
     *
     * @return array<mixed>
     */
    public function listModifiers(): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * POST /orders_calculator — authoritative totals; call BEFORE createOrder.
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    public function calculateOrder(array $body): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * POST /orders — $body carries a client-generated UUIDv4 id (dup → 422).
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    public function createOrder(array $body): array
    {
        return (array) $this->call(__FUNCTION__, func_get_args());
    }
}
