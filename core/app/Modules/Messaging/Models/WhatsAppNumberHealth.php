<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Sender quality/tier health. A sender is "paused" for marketing when it is
 * flagged/restricted or quality has dropped to red. (spec §15.6)
 */
class WhatsAppNumberHealth extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_number_healths';

    protected $guarded = [];

    public static function record(string $provider, string $senderId, array $attrs): void
    {
        static::updateOrCreate(
            ['provider' => $provider, 'sender_id' => $senderId],
            array_filter($attrs, fn ($v) => $v !== null)
        );
    }

    /** False when this sender should not send marketing right now. */
    public static function isHealthy(string $provider, ?string $senderId): bool
    {
        if (!$senderId) {
            return true; // unknown sender -> don't block
        }
        $row = static::where('provider', $provider)->where('sender_id', $senderId)->first();
        if (!$row) {
            return true;
        }
        return !in_array($row->status, ['flagged', 'restricted'], true) && $row->quality !== 'red';
    }
}
