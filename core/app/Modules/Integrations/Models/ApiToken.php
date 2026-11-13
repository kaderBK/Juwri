<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Contracts\Auth\AbilityToken;
use Juwri\Core\Models\User;
use Juwri\Core\Support\TokenAbilities;

/**
 * @property-read User|null $user
 */
class ApiToken extends Model implements AbilityToken
{
    protected $guarded = [];
    protected $casts = ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    protected $hidden = ['token'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resources a token can be scoped to. Combined with an action (:read / :write)
     * these form granular abilities, e.g. `orders:write`, `menu:read`.
     */
    public const RESOURCES = ['restaurants', 'menu', 'orders', 'customers', 'analytics', 'drivers', 'webhooks'];

    /** The full set of abilities selectable when minting a token. */
    public static function selectableAbilities(): array
    {
        $abilities = ['read', 'write'];
        foreach (self::RESOURCES as $res) {
            $abilities[] = "$res:read";
            $abilities[] = "$res:write";
        }
        return $abilities;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Does this token satisfy the ability a route requires?
     *
     * Abilities are `resource:action`. Coarse `read`/`write` are sugar for the
     * `*` resource (`read` = `*:read`, `write` = `*:write`). Coverage rules:
     *  - `*` (or an exact match) grants everything / that ability;
     *  - a `write` action also grants the matching `read`;
     *  - a `*` resource grants that action on any resource.
     * Back-compat: a legacy `['read']` token still reads every resource, and
     * `['write']` still writes everything.
     */
    public function hasAbility(string $required): bool
    {
        $abilities = $this->abilities ?: ['read'];

        foreach ($abilities as $granted) {
            if (self::abilityCovers($granted, $required)) {
                return true;
            }
        }
        return false;
    }

    /** Coverage rules live in Core (shared with OAuth scopes on MCP); kept here for existing callers. */
    public static function abilityCovers(string $granted, string $required): bool
    {
        return TokenAbilities::covers($granted, $required);
    }
}
