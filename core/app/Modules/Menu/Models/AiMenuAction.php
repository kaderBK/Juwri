<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * C4 — a proposed batch of menu mutations from the AI assistant, with an approval gate,
 * optional scheduling/recurrence, and a pre-apply snapshot that makes every apply reversible.
 *
 * @property int $store_id
 * @property string $kind
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $snapshot
 */
class AiMenuAction extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'snapshot' => 'array',
        'run_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    /** The mutation kinds the proposer + applier support (SPEC §2). */
    public const KINDS = [
        'hide_items',
        'show_items',
        'price_adjust',
        'update_descriptions',
        'toggle_category',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
