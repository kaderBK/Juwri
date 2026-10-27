<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;

use Juwri\Core\Money\Concerns\HasMinorMoney;

/**
 * P1-05: a model with `*_minor` money columns — implemented by {@see HasMinorMoney}.
 */
interface MinorMoneyModel
{
    public function minorMoneyCurrency(): string;

    public function rememberMinorMoney(string $major, ?string $value): void;

    public function pendingMinorMoney(string $major): ?string;

    public function money(string $major): ?Money;
}
