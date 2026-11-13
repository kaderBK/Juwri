<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Juwri\Core\Models\Country>
 */
class CountryFactory extends Factory
{
    /** @var class-string<\Illuminate\Database\Eloquent\Model> */
    protected $model = \Juwri\Core\Models\Country::class;

    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->lexify('??'));

        return [
            'code' => $code,
            'name' => fake()->country(),
            'capital' => fake()->city(),
            'continent' => 'Asia',
            'continent_code' => 'AS',
            'phone' => '+'.fake()->numberBetween(1, 998),
            'currency' => 'SAR',
            'symbol' => '⃁', // U+20C1 SAUDI RIYAL SIGN (S5)
            'alpha_3' => $code.'X',
            'default_locale' => 'ar',
            'locales' => ['ar', 'en'],
            'mk_active' => true,
        ];
    }
}
