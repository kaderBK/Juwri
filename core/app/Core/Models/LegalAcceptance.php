<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Legal\LegalAcceptances;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P1-23: a user accepted one version of one legal document (Terms §14.13). Written by
 * {@see LegalAcceptances}; never updated. Kept when the user is anonymised (contract evidence).
 *
 * @property int $id
 * @property int|null $account_id
 * @property int $user_id
 * @property string $document
 * @property string $version
 * @property string $source
 * @property Carbon $accepted_at
 * @property string|null $ip
 * @property string|null $user_agent
 */
class LegalAcceptance extends Model
{
    use BelongsToAccount;

    public const SOURCES = ['signup', 'banner', 'checkout'];

    public $timestamps = false;

    protected $fillable = ['user_id', 'document', 'version', 'source', 'accepted_at', 'ip', 'user_agent'];

    protected $casts = ['accepted_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
