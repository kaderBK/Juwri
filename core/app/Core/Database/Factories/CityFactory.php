<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database\Factories;

use Juwri\Core\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Juwri\Core\Models\City>
 */
class CityFactory extends Factory
{
    /** @var class-string<\Illuminate\Database\Eloquent\Model> */
    protected $model = \Juwri\Core\Models\City::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => fake()->city(),
            'timezone' => 'Asia/Riyadh',
            'lat' => fake()->latitude(20, 30),
            'lng' => fake()->longitude(40, 55),
            'active' => true,
        ];
    }
}
