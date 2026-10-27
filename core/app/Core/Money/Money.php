<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Brick\Money\AllocationMode;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * P1-05 (J25-4): juwri's money value object — brick/money behind a small API. Amounts are exact decimals in the
 * currency's storage exponent ({@see Currencies}); every operation that can lose precision takes an explicit
 * RoundingMode (default HALF_UP = half away from zero, the rule the storefront money core and receipts use).
 *
 * Floats coming from legacy code are read at 15 significant digits first (what PHP ≤ 8.3 round() did), so the
 * result does not depend on the PHP version: PHP 8.4 round(0.035, 2) = 0.03, Money::round(0.035, 2) = 0.04.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(private BrickMoney $money) {}

    public static function ofMinor(int|string $minor, ?string $currency): self
    {
        return new self(BrickMoney::ofMinor($minor, Currencies::brick($currency)));
    }

    public static function ofMajor(int|float|string $major, ?string $currency, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(BrickMoney::of(self::decimal($major), Currencies::brick($currency), roundingMode: $mode));
    }

    public static function zero(?string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    /** Minor units for a major amount (rounded HALF_UP to the currency's exponent). */
    public static function minorFromMajor(int|float|string $major, ?string $currency, RoundingMode $mode = RoundingMode::HalfUp): int
    {
        return self::ofMajor($major, $currency, $mode)->minor();
    }

    /** Major amount (float, for legacy callers) of stored minor units. */
    public static function majorFromMinor(int|string $minor, ?string $currency): float
    {
        return self::ofMinor($minor, $currency)->toFloat();
    }

    /**
     * Round a money amount to $decimals, PHP-version independent (replaces LegacyRound / PHP round() in money
     * paths): the value's 15 significant digits, then $mode (HALF_UP by default).
     */
    public static function round(int|float|string $value, int $decimals = 2, RoundingMode $mode = RoundingMode::HalfUp): float
    {
        if (is_float($value) && ($value == 0.0 || ! is_finite($value))) {
            return $value;
        }

        $rounded = self::decimal($value)->toScale(max(0, $decimals), $mode);
        if ($decimals < 0) {
            $factor = BigDecimal::ten()->power(-$decimals);
            $rounded = $rounded->dividedBy($factor, 0, $mode)->multipliedBy($factor);
        }
        $float = $rounded->toFloat();

        // Keep the sign of a value that rounds to zero (-0.001 → -0.0), as round() does.
        return ($float == 0.0 && self::decimal($value)->isNegative()) ? -0.0 : $float;
    }

    /** An exact decimal from int / numeric string / float (float read at 15 significant digits). */
    public static function decimal(int|float|string|BigNumber $value): BigDecimal
    {
        if ($value instanceof BigNumber) {
            return $value->toBigDecimal();
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Money amount must be finite.');
            }

            return BigDecimal::of(sprintf('%.14e', $value))->strippedOfTrailingZeros();
        }
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return BigDecimal::zero();
        }

        // Exponent notation is accepted only when small ("1.0E-5" is what PHP prints for a float cast to string). brick/math
        // expands the exponent into the number itself, so "1e999999999999" asks it for a terabyte: a memory fatal that no
        // catch stops (found by the P1-18 mutation run). A larger exponent is refused like any other malformed number.
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)[eE]([+-]?\d+)$/', $value, $m) === 1 && abs((int) $m[1]) > self::MAX_EXPONENT) {
            throw new NumberFormatException(sprintf('The given value "%s" does not represent a valid number: its exponent is too large.', substr($value, 0, 40)));
        }

        return BigDecimal::of($value);
    }

    /** Largest |exponent| a numeric string may carry ("1.0E-5" and "1.0E+15" are what PHP prints for floats). */
    private const MAX_EXPONENT = 50;

    public function minor(): int
    {
        return $this->money->getMinorAmount()->toInt();
    }

    /** Major amount as a fixed-scale decimal string ("12.345", "500"). */
    public function major(): string
    {
        return (string) $this->money->getAmount();
    }

    public function toFloat(): float
    {
        return $this->money->getAmount()->toFloat();
    }

    public function currency(): string
    {
        return $this->money->getCurrency()->getCurrencyCode();
    }

    public function exponent(): int
    {
        return $this->money->getCurrency()->getDefaultFractionDigits();
    }

    public function plus(self|int|float|string $that, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self($this->money->plus($that instanceof self ? $that->money : self::decimal($that), $mode));
    }

    public function minus(self|int|float|string $that, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self($this->money->minus($that instanceof self ? $that->money : self::decimal($that), $mode));
    }

    public function multipliedBy(int|float|string $factor, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self($this->money->multipliedBy(self::decimal($factor), $mode));
    }

    public function dividedBy(int|float|string $divisor, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self($this->money->dividedBy(self::decimal($divisor), $mode));
    }

    /** Percentage of this amount (tax, service charge): $percent / 100, rounded with $mode. */
    public function percent(int|float|string $percent, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self($this->money->multipliedBy(self::decimal($percent)->dividedBy(100, 20, RoundingMode::HalfUp), $mode));
    }

    /**
     * Split by ratios without losing a minor unit (split bills): the remainder goes to the largest remainders.
     *
     * @param  list<int|string>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        return array_map(fn (BrickMoney $m) => new self($m), $this->money->allocate($ratios, AllocationMode::FloorToLargestRemainder));
    }

    /** @return list<self> $parts equal shares, first shares take the leftover minor units */
    public function split(int $parts): array
    {
        return $this->allocate(array_fill(0, $parts, 1));
    }

    public function isZero(): bool
    {
        return $this->money->isZero();
    }

    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    public function compareTo(self $that): int
    {
        return $this->money->compareTo($that->money);
    }

    public function toBrick(): BrickMoney
    {
        return $this->money;
    }

    /** @return array{amount: string, minor: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->major(), 'minor' => $this->minor(), 'currency' => $this->currency()];
    }

    public function __toString(): string
    {
        return $this->currency().' '.$this->major();
    }
}
