<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

use RuntimeException;

/**
 * Thrown when a platform-key send is blocked by the monthly quota or an empty
 * wallet. Caught at the call site to show a friendly upsell / top-up prompt.
 * See rev/whatsapp/IMPLEMENTATION-SPEC.md §4 / §16.
 */
class WhatsAppLimitExceededException extends RuntimeException
{
    /** @param string $reason 'quota' | 'funds' | 'disabled' */
    public function __construct(public string $reason = 'quota', string $message = '')
    {
        parent::__construct($message ?: "WhatsApp send blocked: {$reason}.");
    }
}
