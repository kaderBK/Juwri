<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Tracks the 24h customer-service window (and 72h free-entry-point window) per
 * contact. Free-form messages are only allowed while a window is open; outside
 * it, an approved template is required. See rev/whatsapp/IMPLEMENTATION-SPEC.md §14.1.
 */
class WhatsAppWindow extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_windows';

    protected $guarded = [];

    protected $casts = [
        'window_expires_at' => 'datetime',
        'fep_expires_at' => 'datetime',
        'last_inbound_at' => 'datetime',
    ];

    /** True when a free-form send is currently permitted for this contact. */
    public static function isOpen(?int $storeId, string $phone): bool
    {
        $row = static::where('store_id', $storeId)->where('phone', $phone)->first();
        if (! $row) {
            return false;
        }
        $now = now();

        return ($row->window_expires_at && $row->window_expires_at->gt($now))
            || ($row->fep_expires_at && $row->fep_expires_at->gt($now));
    }

    /** Record an inbound message: (re)open the 24h window, optionally 72h FEP. */
    public static function touchInbound(?int $storeId, string $phone, ?string $provider = null, bool $freeEntryPoint = false): void
    {
        $now = now();
        $values = [
            'window_expires_at' => $now->copy()->addHours(24),
            'last_inbound_at' => $now,
        ];
        if ($provider !== null) {
            $values['provider'] = $provider;
        }
        if ($freeEntryPoint) {
            $values['fep_expires_at'] = $now->copy()->addHours(72);
        }

        static::updateOrCreate(
            ['store_id' => $storeId, 'phone' => $phone],
            $values
        );
    }
}
