<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\MoneyBugs4;

/**
 * Bug batch 4 characterisation: every changed expectation is written as ba(before, after). Run with MB4_BEFORE=1
 * against the parent commit to pin the pre-fix value (that run is how the "before" figures were verified);
 * without it the test asserts the fixed value.
 */
trait BeforeAfter
{
    protected function before(): bool
    {
        return getenv('MB4_BEFORE') === '1';
    }

    protected function ba(mixed $before, mixed $after): mixed
    {
        return $this->before() ? $before : $after;
    }
}
