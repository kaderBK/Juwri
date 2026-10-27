<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Order;

/**
 * PARITY-RUN T1-3: a rendered ticket waiting for whoever owns the device.
 *
 * `job_key` is unique — that single constraint is the whole idempotency story
 * (status replays, offline outbox retries and bridge retries all collapse onto
 * the same row instead of printing twice).
 */
class PrintJob extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'width_mm'     => 'integer',
        'copies'       => 'integer',
        'attempts'     => 'integer',
        'claimed_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUSES = ['pending', 'claimed', 'done', 'failed'];

    /** Closed error vocabulary — mirrors print-bridge.md so the UI has one set of reasons. */
    public const ERRORS = [
        'unauthorized', 'unpaired', 'bad_request', 'unknown_target',
        'econnrefused', 'etimedout', 'device_busy', 'payload_too_large', 'internal',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<PrinterProfile, $this> */
    public function profile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'profile_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Order, $this> */
    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'claimed']);
    }

    /** Mark delivered. */
    public function markDone(): void
    {
        $this->forceFill(['status' => 'done', 'error' => null, 'completed_at' => now()])->save();
    }

    /** Mark failed with a reason from the closed vocabulary. */
    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status'   => 'failed',
            'error'    => in_array($error, self::ERRORS, true) ? $error : 'internal',
            'attempts' => (int) $this->attempts + 1,
        ])->save();
    }
}
