<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * P1-05: `reward_discount` is stored as `reward_discount_minor` in the row's `currency_code` (the store's; stamped on
 * first save, moved to the store's current currency when edited). It keeps the store-precision string shape of the
 * old StoreMoney cast. earn_rate / reward_threshold / tiers are stamps or points, not money.
 *
 * @property string|float $reward_discount
 * @property int $reward_discount_minor
 * @property string|null $currency_code
 */
class LoyaltyProgram extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['reward_discount' => 'reward_discount_minor,store'];

    protected $casts = ['active' => 'boolean', 'tiers' => 'array'];

    /** Normalised tiers (name + int min), sorted by min ascending. */
    public function tierList(): array
    {
        $tiers = collect((array) $this->tiers)
            ->map(fn ($t) => ['name' => trim((string) ($t['name'] ?? '')), 'min' => max(0, (int) ($t['min'] ?? 0))])
            ->filter(fn ($t) => $t['name'] !== '')
            ->sortBy('min')->values()->all();

        return $tiers;
    }

    /** The customer's current tier name for a points/stamps balance, or null. */
    public function tierFor(int $balance): ?string
    {
        $current = null;
        foreach ($this->tierList() as $t) {
            if ($balance >= $t['min']) {
                $current = $t['name'];
            } else {
                break;
            }
        }

        return $current;
    }

    public static function forRestaurant(int $storeId): self
    {
        return static::firstOrCreate(['store_id' => $storeId], [
            'type' => 'stamps',
            'earn_rate' => 1,
            'reward_threshold' => 10,
            'reward_description' => 'Free reward',
            'reward_discount' => 0,
            'active' => false,
        ]);
    }

    public function getProgressLabelAttribute(): string
    {
        return $this->type === 'stamps'
            ? ___(':threshold stamps → reward', ['threshold' => $this->reward_threshold])
            : ___(':threshold points → reward', ['threshold' => $this->reward_threshold]);
    }
}
