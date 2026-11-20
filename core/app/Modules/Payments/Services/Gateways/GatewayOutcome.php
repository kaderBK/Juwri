<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Gateways;

use Illuminate\Http\Client\RequestException;

/**
 * grocery-nits2 (REVIEW, Opus: money): which provider answers leave a money call's outcome unknown. A 5xx or a 429
 * does not say whether the provider acted; Stripe (Idempotency-Key) and PayPal (PayPal-Request-Id) store the answer
 * per key and replay it, so the caller retries with the SAME key instead of treating it as a refusal.
 */
final class GatewayOutcome
{
    public static function unknown(RequestException $e): bool
    {
        return $e->response->serverError() || $e->response->status() === 429;
    }
}
