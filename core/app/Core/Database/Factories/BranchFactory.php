<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database\Factories;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->city() . ' Branch',
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('##########'),
            'active' => 1,
            'accepting_orders' => 1,
            'position' => 0,
        ];
    }
}
