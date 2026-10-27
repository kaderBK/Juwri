<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Ability coverage rules for `resource:action` abilities, shared by the owner API token (Integrations) and OAuth scopes
 * mapped to abilities (Agents MCP). Coarse `read`/`write` are sugar for the `*` resource (`read` = `*:read`,
 * `write` = `*:write`). Coverage:
 *  - `*` (or an exact match) grants everything / that ability;
 *  - a `write` action also grants the matching `read`;
 *  - a `*` resource grants that action on any resource.
 */
final class TokenAbilities
{
    public static function covers(string $granted, string $required): bool
    {
        if ($granted === '*' || $granted === $required) {
            return true;
        }

        [$gRes, $gAct] = self::split($granted);
        [$rRes, $rAct] = self::split($required);

        $resOk = $gRes === '*' || $gRes === $rRes;
        $actOk = $gAct === $rAct || ($gAct === 'write' && $rAct === 'read');

        return $resOk && $actOk;
    }

    /**
     * Normalize an ability to [resource, action].
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $ability): array
    {
        if ($ability === 'read') {
            return ['*', 'read'];
        }
        if ($ability === 'write') {
            return ['*', 'write'];
        }
        if (str_contains($ability, ':')) {
            [$res, $act] = explode(':', $ability, 2);

            return [$res, $act === 'write' ? 'write' : 'read'];
        }

        return [$ability, 'read']; // bare resource → read-only on it
    }
}
