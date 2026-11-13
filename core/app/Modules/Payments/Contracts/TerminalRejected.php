<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use RuntimeException;

/** P5-21: a SoftPOS request refused (code: not_enabled, key_reused, not_found, over_refund, not_voidable, not_refundable, provider_unavailable). */
final class TerminalRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
