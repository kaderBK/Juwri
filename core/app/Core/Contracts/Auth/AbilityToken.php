<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Auth;

/**
 * A credential that carries `resource:action` abilities (the owner API token today). Lets modules check a token's
 * abilities without importing the module that stores it (Agents' MCP auth reads it; Integrations owns ApiToken).
 */
interface AbilityToken
{
    public function hasAbility(string $required): bool;
}
