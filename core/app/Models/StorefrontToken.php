<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * GAP-3: per-restaurant public storefront token. Read-only by default; write/agent
 * scopes are explicit. Token value is stored hashed (sha256) and never exposed.
 */
class StorefrontToken extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'scopes' => 'array',
        'allowed_origins' => 'array',
        'active' => 'boolean',
        'browser' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = ['token'];

    /**
     * Selectable scopes when minting a storefront token. `kiosk` (P3-06b, kit/api API-24d): one in-store device — it
     * makes its own store's `menu:read` and `order:write` calls, and what it places is tagged `source=kiosk`.
     */
    public const SCOPES = ['menu:read', 'order:write', 'agent', 'kiosk'];

    /** A kiosk device token (explicit `kiosk` scope; `*` is not a kiosk). */
    public function isKiosk(): bool
    {
        return in_array('kiosk', (array) ($this->scopes ?: []), true);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Does this token satisfy the scope a route requires? Default grant is
     * `menu:read`. `*` grants all; a `:write` scope also grants the matching `:read`.
     */
    public function hasScope(string $required): bool
    {
        $scopes = $this->scopes ?: ['menu:read'];

        if (in_array('*', $scopes, true) || in_array($required, $scopes, true)) {
            return true;
        }
        if (in_array('kiosk', $scopes, true) && in_array($required, ['menu:read', 'order:write'], true)) {
            return true; // API-24d: a kiosk device reads the menu and places orders / bookings of its own store
        }
        if (str_ends_with($required, ':read')) {
            $writeEquiv = substr($required, 0, -5).':write';
            if (in_array($writeEquiv, $scopes, true)) {
                return true;
            }
        }

        return false;
    }
}
