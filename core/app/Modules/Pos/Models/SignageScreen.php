<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * GAPS-RUN-2 B6-P1: a public signage board. The token is the only credential.
 */
class SignageScreen extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Resolve an ACTIVE screen by token, or null (invalid/revoked → null → 404). */
    public static function resolve(string $token): ?self
    {
        return static::where('token', $token)->where('active', true)->first();
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }
}
