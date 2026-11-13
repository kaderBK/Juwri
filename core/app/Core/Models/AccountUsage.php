<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P1-05 (P1-07 follow-up): a running usage counter per counted user (+ their account) and metric — replaces the
 * dynamic platform settings `abuse_storage_bytes_<id>`. One row per (user_id, metric, period); period 'total' is
 * the running total.
 */
class AccountUsage extends Model
{
    use BelongsToAccount;

    public const PERIOD_TOTAL = 'total';

    protected $table = 'account_usage';

    protected $guarded = [];

    protected $casts = [
        'value' => 'integer',
    ];

    public static function value(int $userId, string $metric, string $period = self::PERIOD_TOTAL): int
    {
        return (int) static::query()
            ->where('user_id', $userId)->where('metric', $metric)->where('period', $period)
            ->value('value');
    }

    /** Set an absolute value. */
    public static function put(int $userId, string $metric, int $value, string $period = self::PERIOD_TOTAL): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'metric' => $metric, 'period' => $period],
            ['value' => $value]
        );
    }

    /** Add (or, with a negative delta, subtract — clamped at zero) atomically. */
    public static function adjust(int $userId, string $metric, int $delta, string $period = self::PERIOD_TOTAL): void
    {
        $row = static::firstOrCreate(
            ['user_id' => $userId, 'metric' => $metric, 'period' => $period],
            ['value' => 0]
        );

        if ($delta >= 0) {
            $row->newQuery()->whereKey($row->getKey())->increment('value', $delta);

            return;
        }

        $by = -$delta;
        $row->newQuery()->whereKey($row->getKey())->update([
            'value' => DB::raw('CASE WHEN value >= '.$by.' THEN value - '.$by.' ELSE 0 END'),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, int> user_id => value for one metric/period */
    public static function allFor(string $metric, string $period = self::PERIOD_TOTAL): array
    {
        return static::query()->where('metric', $metric)->where('period', $period)
            ->pluck('value', 'user_id')->map(fn ($v) => (int) $v)->all();
    }
}
