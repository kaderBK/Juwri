<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * SYSTEMS-RUN H-P1: an hourly wage rate for a staff member in a given role.
 *
 * P1-05: `hourly_rate` is stored as `hourly_rate_minor` in the row's `currency_code` (the staff member's store
 * currency; a catalogue row). It keeps the `decimal:2` string shape of the old cast; `money('hourly_rate')` is exact.
 *
 * @property string|float $hourly_rate major amount (decimal:2 string on read)
 * @property int $hourly_rate_minor
 * @property string|null $currency_code
 */
class StaffRoleRate extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use ResolvesStoreForMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['hourly_rate' => 'hourly_rate_minor,decimal:2'];

    /** store_id is filled from the staff member on create; until then reach the store through it. */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('staff_members', 'staff_id', 'store_id');
    }

    public function staff()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }
}
