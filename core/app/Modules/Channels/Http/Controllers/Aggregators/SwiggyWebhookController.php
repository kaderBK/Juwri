<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\Aggregators;

use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyPayloadMapper;

/**
 * PARITY-RUN T7 — Swiggy's inbound webhook. All behaviour (and the reasoning behind
 * the response contract) lives in {@see IndiaAggregatorWebhookController}.
 */
class SwiggyWebhookController extends IndiaAggregatorWebhookController
{
    protected function providerKey(): string
    {
        return 'swiggy';
    }

    protected function isCancellation(array $payload): bool
    {
        return SwiggyPayloadMapper::isCancellation($payload);
    }
}
