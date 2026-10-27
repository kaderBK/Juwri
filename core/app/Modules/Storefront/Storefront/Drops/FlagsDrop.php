<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * P2-2: the `flags` global — resolved ordering capabilities for this render
 * (plan + owner settings already folded in by StoreController). Booleans only.
 */
class FlagsDrop extends Drop
{
    /** @param  array<string,bool>  $flags */
    public function __construct(protected array $flags = []) {}

    protected function flag(string $key): bool
    {
        return (bool) ($this->flags[$key] ?? false);
    }

    public function allow_order(): bool
    {
        return $this->flag('allow_order');
    }

    public function on_table(): bool
    {
        return $this->flag('on_table');
    }

    public function takeaway(): bool
    {
        return $this->flag('takeaway');
    }

    public function delivery(): bool
    {
        return $this->flag('delivery');
    }

    /** Hotel room-service ordering (a room table + hotel mode on). */
    public function room_service(): bool
    {
        return $this->flag('room_service');
    }

    public function payment(): bool
    {
        return $this->flag('payment');
    }

    public function scheduling(): bool
    {
        return $this->flag('scheduling');
    }
}
