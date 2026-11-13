<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Tools;

use Closure;
use Juwri\Core\Models\Store;

/**
 * P1-16: who is calling a tool, resolved by the channel from its own authentication — never from tool arguments.
 * Owner tools read {@see $userId} (the authenticated account); storefront tools read {@see $store} (bound by the
 * channel's token / phone line / widget) and {@see $sessionPhone} (the TRUSTED sender identity, null when the caller
 * is not phone-verified: web chat and MCP).
 */
final class ToolContext
{
    /**
     * @param  (Closure(string): bool)|null  $abilities  does the caller's credential carry this ability? null = none
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?int $userId = null,
        public readonly ?Store $store = null,
        public readonly ?string $sessionPhone = null,
        private readonly ?Closure $abilities = null,
    ) {}

    public static function forStore(Store $store, string $channel, ?string $sessionPhone = null): self
    {
        return new self($channel, null, $store, $sessionPhone);
    }

    /** @param  Closure(string): bool  $abilities */
    public static function forOwner(int $userId, string $channel, Closure $abilities): self
    {
        return new self($channel, $userId, null, null, $abilities);
    }

    /** A null ability is always allowed; otherwise the caller's credential must carry it. */
    public function can(?string $ability): bool
    {
        if ($ability === null) {
            return true;
        }

        return $this->abilities !== null && ($this->abilities)($ability);
    }
}
