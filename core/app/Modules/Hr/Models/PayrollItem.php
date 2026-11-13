<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Support\RequestEpoch;

/**
 * SYSTEMS-RUN P-P1: one staff member's line on a payroll run.
 *
 * P1-05: the money columns are stored as `*_minor` in the row's `currency_code` (its payroll run's, stamped on first
 * save; never re-interpreted). They keep the store-precision string shape of the old StoreMoney cast. Query with
 * the `*_minor` columns (sums: `PayrollItem::minorMoneySql('net')`).
 *
 * @property string|float $rate major amount (store-precision string on read)
 * @property string|float $base_pay major amount (store-precision string on read)
 * @property string|float $overtime_pay major amount (store-precision string on read)
 * @property string|float $tips major amount (store-precision string on read)
 * @property string|float $bonuses major amount (store-precision string on read)
 * @property string|float $deductions major amount (store-precision string on read)
 * @property string|float $net major amount (store-precision string on read)
 * @property int $rate_minor
 * @property int $base_pay_minor
 * @property int $overtime_pay_minor
 * @property int $tips_minor
 * @property int $bonuses_minor
 * @property int $deductions_minor
 * @property int $net_minor
 * @property string|null $currency_code
 */
class PayrollItem extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use ResolvesStoreForMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = [
        'rate' => 'rate_minor,store',
        'base_pay' => 'base_pay_minor,store',
        'overtime_pay' => 'overtime_pay_minor,store',
        'tips' => 'tips_minor,store',
        'bonuses' => 'bonuses_minor,store',
        'deductions' => 'deductions_minor,store',
        'net' => 'net_minor,store',
    ];

    /**
     * The money columns are store money and are decimal(_,3) in the schema; the
     * *_hours columns are decimal(8,2) and are NOT money — they are hours, and a
     * store's currency has nothing to say about them. Before this block existed the
     * money columns were uncast, so they emitted MySQL's raw 3dp string to EVERY
     * store: a USD run rendered '1200.000'. The store-precision format (MinorMoney `store`, formerly the StoreMoney
     * cast) puts them back on the store's
     * own precision, leaving a 2-decimal store at '1200.00' and letting an OMR store
     * keep its baisa.
     */
    protected $casts = [
        'adjustments' => 'array',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @var array<int, string> payroll run id => its currency_code, per application (a run's currency never changes) */
    protected static array $runCurrencyMemo = [];

    /** The application instance $runCurrencyMemo was filled for. */
    protected static ?int $memoApp = null;

    /** An item is in its payroll run's currency (else its store's) — one lookup per run, not per row. */
    public function defaultMinorMoneyCurrency(): string
    {
        $runId = (int) $this->getAttribute('payroll_run_id');
        if ($runId > 0) {
            $id = RequestEpoch::id();
            if (static::$memoApp !== $id) {
                static::$memoApp = $id;
                static::$runCurrencyMemo = [];
            }
            if (! isset(static::$runCurrencyMemo[$runId])) {
                $code = DB::table('payroll_runs')->where('id', $runId)->value('currency_code');
                if ($code) {
                    static::$runCurrencyMemo[$runId] = strtoupper((string) $code);
                }
            }
            if (isset(static::$runCurrencyMemo[$runId])) {
                return static::$runCurrencyMemo[$runId];
            }
        }

        return $this->defaultStoreOrPlatformCurrency();
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * HasMinorMoney (row currency): an item reaches its store through its run. Note the FK is
     * `payroll_run_id` — the migration's own comment calls it `run_id`, which is the
     * relation's name, not the column's.
     */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('payroll_runs', 'payroll_run_id', 'store_id');
    }

    public function staff()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }
}
