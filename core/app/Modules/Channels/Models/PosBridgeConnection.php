<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;

/**
 * GAPS-RUN-2 B1: maps a restaurant/branch to a third-party POS merchant/location.
 * The trusted tenant identity for an outbound push; per-tenant POS credentials
 * live in the Credential vault (feature = provider name), never on this row.
 */
class PosBridgeConnection extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'last_push_at' => 'datetime',
        'settings' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    /** The active connection for a restaurant + provider, or null. */
    public static function resolve(string $provider, int $storeId): ?self
    {
        return static::where('provider', $provider)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * The inbound-webhook shared secret, kept in the encrypted Credential vault
     * (feature "{provider}_webhook") — never in the plaintext settings column.
     */
    public function webhookToken(): ?string
    {
        $cred = Credential::where('feature', $this->provider.'_webhook')
            ->where('store_id', $this->store_id)
            ->where('active', true)
            ->first();

        return $cred?->secrets['webhook_token'] ?? null;
    }

    /** Return the connection's webhook token, minting + vaulting one on first use. */
    public function ensureWebhookToken(): string
    {
        $cred = Credential::firstOrNew([
            'feature' => $this->provider.'_webhook',
            'store_id' => $this->store_id,
        ]);
        $token = $cred->secrets['webhook_token'] ?? null;
        if (! $token) {
            $token = bin2hex(random_bytes(20));
            $cred->provider = $this->provider;
            $cred->secrets = ['webhook_token' => $token];
            $cred->active = true;
            $cred->save();
        }

        return $token;
    }

    /**
     * Resolve the tenant connection for an inbound webhook strictly from its shared
     * secret. Scans the vaulted "{provider}_webhook" credentials with a constant-time
     * compare; unknown/empty token → null (caller 404s, no side effects).
     */
    public static function resolveByWebhookToken(string $provider, string $token): ?self
    {
        if ($token === '') {
            return null;
        }
        $cred = Credential::where('feature', $provider.'_webhook')
            ->where('active', true)
            ->get()
            ->first(fn ($c) => hash_equals((string) ($c->secrets['webhook_token'] ?? ''), $token));

        if (! $cred) {
            return null;
        }

        return static::where('provider', $provider)
            ->where('store_id', $cred->store_id)
            ->first();
    }
}
