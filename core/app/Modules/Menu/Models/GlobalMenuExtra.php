<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use Illuminate\Database\Eloquent\Model;

/** WI-G4: a platform-level add-on/extra (no store_id). *
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 * @property string|float|null $price
 * @property int|null $price_minor
 * @property string|null $currency_code
 */
class GlobalMenuExtra extends Model implements \Juwri\Core\Money\MinorMoneyModel
{
    use \Juwri\Core\Money\Concerns\HasMinorMoney;

    /** @var array<string, string> P1-05: platform currency; '12.500' strings as the old decimal:3 cast */
    protected array $minorMoney = ['price' => 'price_minor,decimal:3'];

    protected $fillable = ['title', 'price', 'translations', 'position', 'active'];

    protected $casts = ['active' => 'boolean', 'translations' => 'array'];
}
