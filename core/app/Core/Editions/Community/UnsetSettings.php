<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

/**
 * P1-13: the `settings` object of the Community edition plan. Every key is unset (null = no limit / config
 * default), so legacy `$user->plan()->settings->x_limit` reads stay warning-free.
 */
final class UnsetSettings
{
    public function __get(string $name): mixed
    {
        return null;
    }

    public function __isset(string $name): bool
    {
        return false;
    }
}
