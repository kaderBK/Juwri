<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Database\Factories;

use Juwri\Core\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Zone>
 */
class ZoneFactory extends Factory
{
    /** @var class-string<\Illuminate\Database\Eloquent\Model> */
    protected $model = \App\Models\Zone::class;

    public function definition(): array
    {
        // 0.1° square around a base point.
        $lat = fake()->latitude(20, 30);
        $lng = fake()->longitude(40, 55);

        return [
            'city_id' => City::factory(),
            'name' => fake()->streetName().' Zone',
            'polygon' => [
                [$lat, $lng],
                [$lat + 0.1, $lng],
                [$lat + 0.1, $lng + 0.1],
                [$lat, $lng + 0.1],
            ],
            'base_fee' => 5,
            'per_km_fee' => 1.5,
            'min_order' => 20,
            'active' => true,
        ];
    }
}
