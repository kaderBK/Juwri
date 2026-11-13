<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Content\HelpPillars;

/** Community default: no edition help pillars. Owner and shopper guides stay in the Community docs tree. */
final class NoHelpPillars implements HelpPillars
{
    public function directory(string $pillar): ?string
    {
        return null;
    }
}
