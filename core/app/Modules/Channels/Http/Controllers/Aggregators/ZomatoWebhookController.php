<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\Aggregators;

use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoPayloadMapper;

/**
 * PARITY-RUN T7 — Zomato's inbound webhook. All behaviour (and the reasoning behind
 * the response contract) lives in {@see IndiaAggregatorWebhookController}; a
 * dedicated route + class per provider exists because partners are configured with
 * ONE fixed callback URL each, and because their retry/ack semantics will diverge
 * once certification lands.
 */
class ZomatoWebhookController extends IndiaAggregatorWebhookController
{
    protected function providerKey(): string
    {
        return 'zomato';
    }

    protected function isCancellation(array $payload): bool
    {
        return ZomatoPayloadMapper::isCancellation($payload);
    }
}
