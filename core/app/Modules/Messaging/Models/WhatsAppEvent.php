<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppEvent extends Model
{
    protected $table = 'whatsapp_events';

    protected $guarded = [];

    protected $casts = ['received_at' => 'datetime'];

    /**
     * Atomically claim a provider event id. Returns true the first time it's
     * seen, false on a duplicate re-delivery. (spec §15.1)
     */
    public static function claim(string $provider, string $providerMsgId, string $kind = 'inbound'): bool
    {
        try {
            // Savepoint: on PostgreSQL a failed insert would abort an enclosing transaction.
            \Illuminate\Support\Facades\DB::transaction(fn () => static::create([
                'provider' => $provider,
                'provider_msg_id' => $providerMsgId,
                'kind' => $kind,
                'received_at' => now(),
            ]));
            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            return false; // unique violation => already processed
        }
    }
}
