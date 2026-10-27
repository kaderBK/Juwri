<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * An import failure that is safe to print and log: the phase, the error class and (for database errors) the SQL state
 * only. The original exception is NOT chained: a QueryException message holds the SQL with its bound values (emails,
 * password hashes, phone numbers of real customers at cut-over).
 */
final class ImportFailed extends RuntimeException
{
    public static function from(Throwable $e, string $phase): self
    {
        if ($e instanceof self) {
            return $e;
        }

        return new self('legacy:import '.$phase.' failed ('.self::describe($e).'); rows of this phase were rolled back');
    }

    /** Class name and, for a database error, its SQL state: never the message. */
    public static function describe(Throwable $e): string
    {
        $class = (new \ReflectionClass($e))->getShortName();

        return $e instanceof QueryException ? $class.' SQLSTATE '.(string) $e->getCode() : $class;
    }
}
