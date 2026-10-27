<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * J27-A2: a client order key (client_order_id / Idempotency-Key) was resent with a DIFFERENT cart than the order
 * it already created. Never a second order: the caller answers 409 and the client starts a new order (new key).
 * Rendered as a plain 409 JSON answer when a caller lets it through.
 */
class ClientOrderConflict extends ConflictHttpException
{
    public function __construct(public readonly int $orderId)
    {
        parent::__construct(___('This order was already placed with a different cart. Start a new order.'));
    }
}
