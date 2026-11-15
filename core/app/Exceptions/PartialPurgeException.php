<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Exceptions;

use RuntimeException;

/**
 * Some stores of an account could not be erased. The owner's own erasure is committed and the report is saved, but the
 * purge is NOT done: the caller must count it as a failed attempt and alert, never report "Purged".
 */
class PartialPurgeException extends RuntimeException
{
    /** @param array<string, mixed> $report */
    public function __construct(public readonly array $report)
    {
        $ids = implode(', ', array_keys((array) ($report['failed_stores'] ?? [])));
        parent::__construct('purge incomplete: store(s) '.$ids.' could not be erased');
    }
}
