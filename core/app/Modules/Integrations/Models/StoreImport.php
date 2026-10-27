<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * A requested restore/import of a backup ZIP into a store. Lifecycle:
 * uploaded → validating → previewed → processing → done | failed. See the
 * migration for the "why". Import is additive/upsert and ownership-scoped.
 */
class StoreImport extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'store_id', 'user_id', 'token', 'status', 'path', 'size_bytes',
        'manifest', 'plan', 'summary', 'cross_store', 'error',
        'only_datasets', // HPMS E14(e): owner's confirm-time dataset selection
    ];

    protected $casts = [
        'manifest'    => 'array',
        'plan'        => 'array',
        'summary'     => 'array',
        'only_datasets' => 'array',
        'cross_store' => 'boolean',
        'size_bytes'  => 'integer',
    ];

    public const STATUSES = ['uploaded', 'validating', 'previewed', 'processing', 'done', 'failed'];

    public static function newToken(): string
    {
        return Str::lower(Str::random(40));
    }

    public function isPreviewed(): bool
    {
        return $this->status === 'previewed';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }
}
