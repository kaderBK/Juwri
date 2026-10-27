<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use RuntimeException;

/** An offline record the replay refuses after looking at it inside its transaction (rolled back, then stored as refused). */
final class RefusedRecord extends RuntimeException
{
    /** @param  array<string, mixed>  $result */
    public function __construct(public readonly string $status, public readonly ?string $reason, public readonly array $result = [])
    {
        parent::__construct("$status: $reason");
    }
}
