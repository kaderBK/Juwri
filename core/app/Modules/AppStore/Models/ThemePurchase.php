<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Models;

use App\Models\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * P4-1: a per-account theme license (unique user+theme).
 *
 * P1-05: `price_paid` is stored as `price_paid_minor` in the row's `currency_code` (the platform currency, stamped on
 * first save; a fact). It reads as a number (the column was uncast).
 *
 * @property float $price_paid major amount
 * @property int $price_paid_minor
 * @property string|null $currency_code
 */
class ThemePurchase extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['price_paid' => 'price_paid_minor'];

    /** @var array<string, string> */
    protected $casts = ['refunded_at' => 'datetime'];

    /** P6-243: a refunded licence no longer licenses the theme. */
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('refunded_at');
    }

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
