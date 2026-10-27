<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Editions\Edition;

/** P1-13 Community default: the Community edition — no licensed ee features, one account. */
final class CommunityEdition implements Edition
{
    public function name(): string
    {
        return 'community';
    }

    public function isCommunity(): bool
    {
        return true;
    }

    public function licensed(string $feature): bool
    {
        return false;
    }

    public function maxTenants(): int
    {
        return 1;
    }

    public function notice(): ?string
    {
        return null;
    }

    public function licensedTo(): ?array
    {
        return null;
    }
}
