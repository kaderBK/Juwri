<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * theme-pack-blade F1: a customer's recurring meal subscription. `items` holds
 * the order lines replayed on each due `next_run`; `cadence` (+ `days` for
 * custom weekdays) drives {@see advanceNextRun()}. Generation itself lives in
 * the `meals:generate-due` command, which reuses OrderApiService (UNPAID).
 */
class MealPlan extends Model
{
    use BelongsToAccount;
    use HasFactory;

    public const CADENCES = ['daily', 'weekly', 'custom'];

    protected $fillable = [
        'customer_id',
        'store_id',
        'name',
        'cadence',
        'days',
        'start_date',
        'next_run',
        'status',
        'items',
    ];

    protected $casts = [
        'days' => 'array',
        'items' => 'array',
        'start_date' => 'date',
        'next_run' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Compute the run date that follows the given date under this plan's cadence.
     * daily → +1d, weekly → +7d, custom → the next configured weekday (0=Sun).
     */
    public function nextRunAfter(Carbon $from): Carbon
    {
        $from = $from->copy()->startOfDay();

        if ($this->cadence === 'daily') {
            return $from->addDay();
        }

        if ($this->cadence === 'custom') {
            $days = array_values(array_filter(
                array_map('intval', (array) ($this->days ?? [])),
                fn ($d) => $d >= 0 && $d <= 6
            ));
            if (empty($days)) {
                return $from->addWeek(); // no weekdays configured → behave weekly
            }
            sort($days);
            for ($i = 1; $i <= 7; $i++) {
                $candidate = $from->copy()->addDays($i);
                if (in_array((int) $candidate->dayOfWeek, $days, true)) {
                    return $candidate;
                }
            }

            return $from->addWeek(); // unreachable, defensive
        }

        // weekly (default)
        return $from->addWeek();
    }

    /**
     * Advance `next_run` one cadence period past its current value and persist.
     */
    public function advanceNextRun(): void
    {
        $base = $this->next_run ? $this->next_run->copy() : Carbon::today();
        $this->next_run = $this->nextRunAfter($base);
        $this->save();
    }
}
