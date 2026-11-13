<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

use RuntimeException;

/**
 * Thrown on a single-provider transport/HTTP failure. The orchestrator catches
 * this and advances to the next provider in the fallback chain.
 */
class AiProviderException extends RuntimeException implements \Juwri\Modules\Ai\Contracts\AiProviderFailure
{
    public function __construct(
        string $message,
        public string $provider = '',
        public bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
