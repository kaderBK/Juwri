<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Transport\Relay;

use RuntimeException;

/** A partner call the juwri Cloud relay refused or could not make; `code` is the relay's answer (e.g. store_limit). */
final class RelayRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
