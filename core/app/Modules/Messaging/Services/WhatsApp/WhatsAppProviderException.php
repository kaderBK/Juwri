<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

use RuntimeException;

/**
 * Thrown on a single-provider transport/HTTP failure. The orchestrator catches
 * this and (per config) falls back to a wa.me link. $retryable maps provider
 * error codes to whether the send job should back off and retry — see
 * rev/whatsapp/IMPLEMENTATION-SPEC.md §15.4.
 */
class WhatsAppProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public string $provider = '',
        public bool $retryable = true,
        public ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
