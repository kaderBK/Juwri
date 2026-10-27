<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

use RuntimeException;

/**
 * Thrown when the owner's monthly AI usage exceeds their plan allowance.
 * Caught at the controller layer and surfaced as a friendly message.
 */
class AiLimitExceededException extends RuntimeException implements \Juwri\Modules\Ai\Contracts\AiLimitExceeded
{
    public function __construct(string $message = 'AI usage limit reached for this month. Please upgrade your plan or try again next month.')
    {
        parent::__construct($message);
    }
}
