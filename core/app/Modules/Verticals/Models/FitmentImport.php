<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;

/**
 * P3-08b (D12): one store fitment CSV upload — its preview, counts, the first failing rows and its state.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property int|null $user_id
 * @property string $original_name
 * @property string|null $path
 * @property string $status previewed|queued|running|done|failed
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $error_rows
 * @property int $duplicate_rows
 * @property int $imported_rows
 * @property list<array<string, mixed>>|null $sample
 * @property list<array{row: int, message: string, values?: string}>|null $errors
 * @property array<string, mixed>|null $meta
 * @property string|null $message
 */
class FitmentImport extends Model
{
    use BelongsToAccount;
    use HasUuids;
    use StoresUtc;

    protected $table = 'fitment_imports';

    protected $guarded = ['id', 'account_id'];

    protected $casts = [
        'store_id' => 'integer',
        'user_id' => 'integer',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'imported_rows' => 'integer',
        'sample' => 'array',
        'errors' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
