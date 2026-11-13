<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Database\Factories;

use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => 0,
            'amount' => fake()->numberBetween(10, 500),
            'currency_code' => 'SAR',
            'product_name' => 'Plan',
            'transaction_method' => 'wallet_topup',
            'status' => null,
            'payment_id' => null,
        ];
    }

    public function settled(): static
    {
        return $this->state(fn () => ['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'pay_' . fake()->uuid()]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => null]);
    }
}
