<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money\Concerns;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Money\Casts\MinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Tenancy\TenantFill;

/**
 * P1-05 (J25-4): money stored as `*_minor` integers, exposed under the legacy major-unit name for compatibility.
 *
 * The model declares `protected array $minorMoney = ['price' => 'price_minor', 'cost' => 'cost_minor,store', …]`
 * (major attribute => minor column[,output format — see {@see MinorMoney}]). Then:
 *  - `$m->price` reads / writes the major amount (float, like the old decimal cast); `$m->price_minor` is the int;
 *  - `$m->money('price')` is a {@see Money} value object;
 *  - toArray()/JSON keep the major key (clients read it) and add the `*_minor` key;
 *  - the row's currency is its `currency_code` column when the table has one (filled on save from the store's
 *    currency, else the platform's), so a later change of the store currency does not reinterpret stored amounts;
 *  - amounts set before the currency is known (fill order, store_id set later) are converted again on save.
 *
 * Query-builder code must use the `*_minor` column (where/orderBy/sum/raw SQL) — the major name is not a column.
 *
 * @phpstan-require-extends Model
 */
trait HasMinorMoney
{
    /** @var array<string, string|null> major key => exact decimal string set since the last save */
    protected array $minorMoneyPending = [];

    /** @var array<class-string, array<string, string>> P6-49: the casts this trait adds, per model class */
    private static array $minorMoneyCastsMemo = [];

    public function initializeHasMinorMoney(): void
    {
        /* Runs for every model instance made or unserialized (2,000 cached items per storefront render): the spec is a
           class-level constant, so the cast list is worked out once per class, not once per instance. */
        $this->mergeCasts(self::$minorMoneyCastsMemo[static::class] ??= $this->minorMoneyCasts());
    }

    /** @return array<string, string> */
    private function minorMoneyCasts(): array
    {
        $casts = [];
        $map = $this->minorMoneyMap();
        foreach ($this->minorMoneySpec() as $major => $spec) {
            $casts[$major] = MinorMoney::class.':'.$spec;
            $casts[$map[$major]] = 'integer';
        }

        return $casts;
    }

    /**
     * Settle before every save — also saveQuietly() (no model events) and pivots saved by attach()/sync().
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        $this->settleMinorMoney();

        return parent::save($options);
    }

    /** @return array<string, string> major attribute => minor column */
    public function minorMoneyMap(): array
    {
        return array_map(fn (string $spec) => explode(',', $spec, 2)[0], $this->minorMoneySpec());
    }

    /** @return array<string, string> major attribute => "minor_column[,format]" */
    public function minorMoneySpec(): array
    {
        return (array) (get_object_vars($this)['minorMoney'] ?? []);
    }

    /**
     * Raw SQL for a money attribute as a major-unit decimal in each row's own currency, for aggregates:
     * `->sum(DB::raw(Order::minorMoneySql('grand_total')))`, `selectRaw('SUM('.Order::minorMoneySql('tip_amount').')')`.
     * $qualifier is prepended raw to both columns — pass `DB::getTablePrefix().'orders.'` in joins.
     */
    public static function minorMoneySql(string $major, string $qualifier = ''): string
    {
        $model = static::query()->getModel();
        $minor = $model->minorMoneyMap()[$major] ?? throw new \InvalidArgumentException(static::class." has no money attribute [{$major}]");
        $currency = $model->minorMoneyCurrencyColumn();

        return Currencies::sqlMajor($qualifier.$minor, $currency === null ? null : $qualifier.$currency);
    }

    /** Column holding the row's ISO currency, or null when the table has none. */
    public function minorMoneyCurrencyColumn(): ?string
    {
        return property_exists($this, 'minorMoneyCurrencyColumn') ? $this->minorMoneyCurrencyColumn : 'currency_code';
    }

    /**
     * Whether an edited row moves to its store's CURRENT currency (catalogue rows: yes). Rows whose currency is a
     * fact of the past (orders, payments) override this to return false.
     */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return true;
    }

    /** The currency the row's amounts are stored in. */
    public function minorMoneyCurrency(): string
    {
        $column = $this->minorMoneyCurrencyColumn();
        $code = $column !== null ? (string) ($this->attributes[$column] ?? '') : '';

        return $code !== '' ? strtoupper($code) : $this->defaultMinorMoneyCurrency();
    }

    /** Currency for a row that has none yet: its store's, else the platform's. Models may override. */
    public function defaultMinorMoneyCurrency(): string
    {
        return $this->defaultStoreOrPlatformCurrency();
    }

    public function defaultStoreOrPlatformCurrency(): string
    {
        $storeId = $this->attributes['store_id']
            ?? (method_exists($this, 'storeIdForMoney') ? $this->storeIdForMoney() : null);
        if ($storeId && function_exists('store_currency')) {
            return strtoupper(store_currency((int) $storeId));
        }

        // Bug batch 10 · 1: the platform currency, not a storefront / payment page's display swap of settings.currency_code.
        return function_exists('platform_currency') ? platform_currency() : strtoupper((string) (config('settings.currency_code') ?: 'USD'));
    }

    public function money(string $major): ?Money
    {
        $minor = $this->minorMoneyMap()[$major] ?? null;
        if ($minor === null) {
            return null;
        }
        $value = $this->getAttribute($minor);

        return $value === null ? null : Money::ofMinor((int) $value, $this->minorMoneyCurrency());
    }

    /** @internal used by {@see MinorMoney} */
    public function rememberMinorMoney(string $major, ?string $value): void
    {
        $this->minorMoneyPending[$major] = $value;
    }

    /** @internal used by {@see MinorMoney} */
    public function pendingMinorMoney(string $major): ?string
    {
        return $this->minorMoneyPending[$major] ?? null;
    }

    /** Fix the row currency and convert amounts set since the last save with it. */
    public function settleMinorMoney(): void
    {
        // A raw major key (Pivot::fromRawAttributes on attach(), setRawAttributes) is not a column: convert it.
        foreach (array_keys($this->minorMoneyMap()) as $major) {
            if (array_key_exists($major, $this->attributes)) {
                $raw = $this->attributes[$major];
                unset($this->attributes[$major]);
                $this->minorMoneyPending[$major] = ($raw === null || $raw === '') ? null : (string) Money::decimal(is_numeric($raw) ? $raw : (float) $raw);
            }
        }
        $column = $this->minorMoneyCurrencyColumn();
        if ($column !== null) {
            $current = strtoupper((string) ($this->attributes[$column] ?? ''));
            if ($current === '' || ($this->minorMoneyPending !== [] && $this->minorMoneyFollowsStoreCurrency())) {
                // A child row gets its store_id from its parent on `creating` (after `saving`): fill it now so the
                // row takes its store's currency, not the platform's.
                if (empty($this->attributes['store_id']) && method_exists($this, 'bootBelongsToAccount')) {
                    TenantFill::fill($this);
                }
                $target = strtoupper($this->defaultMinorMoneyCurrency());
                if ($current !== '' && $current !== $target) {
                    // The store changed currency since this row was written and an amount is being edited now: move
                    // the untouched amounts to the new currency keeping their number (what the decimal column did).
                    foreach ($this->minorMoneyMap() as $major => $minor) {
                        $value = $this->attributes[$minor] ?? null;
                        if (! array_key_exists($major, $this->minorMoneyPending) && $value !== null && $value !== '') {
                            $this->attributes[$minor] = Money::minorFromMajor(Money::ofMinor((int) $value, $current)->major(), $target);
                        }
                    }
                }
                $this->attributes[$column] = $target;
            }
        }
        $currency = $this->minorMoneyCurrency();
        $map = $this->minorMoneyMap();
        foreach ($this->minorMoneyPending as $major => $value) {
            if (isset($map[$major])) {
                $this->attributes[$map[$major]] = $value === null ? null : Money::minorFromMajor($value, $currency);
            }
        }
        $this->minorMoneyPending = [];
    }

    /** JSON/array output keeps the legacy major keys (API compatibility) next to the new `*_minor` keys. */
    public function attributesToArray()
    {
        $array = parent::attributesToArray();
        $hidden = $this->getHidden();
        $visible = $this->getVisible();
        foreach ($this->minorMoneyMap() as $major => $minor) {
            if (! array_key_exists($minor, $this->attributes) || in_array($major, $hidden, true)
                || ($visible !== [] && ! in_array($major, $visible, true) && ! in_array($minor, $visible, true))) {
                continue;
            }
            $array[$major] = $this->getAttribute($major);
        }

        return $array;
    }
}
