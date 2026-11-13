<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Juwri\Modules\Payments\Models\LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    /** @var class-string<\Illuminate\Database\Eloquent\Model> */
    protected $model = \Juwri\Modules\Payments\Models\LedgerAccount::class;

    public function definition(): array
    {
        return [
            'owner_type' => 'merchant',
            'owner_id'   => fake()->unique()->numberBetween(1, 1_000_000),
            'kind'       => 'merchant',
            'currency'   => 'USD',
            'status'     => 'active',
        ];
    }

    public function platform(): static
    {
        return $this->state(['owner_type' => 'platform', 'owner_id' => 0, 'kind' => 'platform']);
    }
}
