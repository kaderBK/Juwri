<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Concerns;

/**
 * P6-40 (PG-SQL-AUDIT O1): email addresses are stored trimmed and lowercased, so `User@X.com` and `user@x.com`
 * are one account on every driver (PostgreSQL and sqlite compare text case-sensitively, MySQL does not).
 */
trait NormalisesEmail
{
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }
}
