<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Content;

use Juwri\Core\Editions\Community\NoHelpPillars;

/**
 * Help-center pillars an edition ships outside the Community docs tree.
 * Community has none ({@see NoHelpPillars}). An edition package may bind a
 * directory for a pillar name. The public Dev MCP corpus does not read this.
 */
interface HelpPillars
{
    /** Absolute directory for a named help pillar, or null when this edition does not ship it. */
    public function directory(string $pillar): ?string;
}
