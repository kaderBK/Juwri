<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Marn;

use RuntimeException;

/**
 * Thrown when Marn rejects an order push because the deterministic idempotency key was
 * already used on a prior attempt — the natural idempotency signal. The driver catches it
 * and treats the push as already-succeeded (never a hard failure, never a duplicate PAID
 * order). This closes the "HTTP succeeded at Marn but the response was lost, job retries"
 * window that the (order_id, provider) ledger alone cannot, because the ledger row is only
 * marked pushed after the call returns.
 */
class MarnDuplicateException extends RuntimeException
{
}
