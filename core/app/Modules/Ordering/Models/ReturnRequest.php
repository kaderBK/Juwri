<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-08 (aisle §10, D7): a shopper's "Start a return" for lines of a goods order, inside the store's returns window.
 * `resolution` is what the shopper asked for: an `exchange` or `store_credit`, or a `refund` — offered only when the
 * owner turned returns refunds on (D7: off by default, the returns copy then says exchange or store credit only).
 * The owner approves or rejects it, marks the goods received and completes it (credit or refund recorded then).
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string $status requested|approved|rejected|received|completed|cancelled
 * @property string $resolution exchange|store_credit|refund
 * @property string|null $reason
 * @property list<array{order_item_id: int, qty: int}> $lines
 * @property int $amount_minor
 * @property int|null $credited_minor
 * @property int|null $refunded_minor
 * @property string|null $currency_code
 * @property string|null $owner_note
 * @property Carbon|null $decided_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class ReturnRequest extends Model
{
    use BelongsToAccount;
    use HasUuids;

    public const STATUSES = ['requested', 'approved', 'rejected', 'received', 'completed', 'cancelled'];

    public const RESOLUTIONS = ['exchange', 'store_credit', 'refund'];

    /** Open requests: a line in one of them cannot be asked for again. */
    public const OPEN = ['requested', 'approved', 'received'];

    protected $table = 'return_requests';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'lines' => 'array',
        'amount_minor' => 'integer',
        'credited_minor' => 'integer',
        'refunded_minor' => 'integer',
        'decided_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
