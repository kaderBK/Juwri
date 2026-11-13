<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * FEATURES-5 Track C: a customer-recorded voice note attached to an order.
 *
 * Files live under the PRIVATE disk at voice-notes/{store_id}/{filename}
 * (never public storage) and are only reachable through the authorized
 * streaming routes. `transcript` is a seam column (transcription not built).
 */
class OrderVoiceNote extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'duration_seconds' => 'integer',
        'size_bytes' => 'integer',
    ];

    /** Accepted upload mime types (webm/opus + iOS mp4/AAC + ogg fallbacks). */
    public const ALLOWED_MIMES = [
        'audio/webm',
        'audio/ogg',
        'audio/mp4',
        'audio/m4a',
        'audio/x-m4a',
        'audio/aac',
        'audio/mpeg',
        'video/webm', // some browsers label opus-in-webm as video/webm
    ];

    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    public const MAX_SECONDS = 60;

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Relative path on the private disk. */
    public function relativePath(): string
    {
        return 'voice-notes/'.$this->store_id.'/'.$this->filename;
    }

    /** Generate a random, non-guessable token (>=40 chars). */
    public static function newToken(): string
    {
        return Str::random(48);
    }
}
