<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database\Factories;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Store> */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $title = 'Store ' . fake()->unique()->company();

        return [
            'user_id' => User::factory(),
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111111',
            'title' => $title,
            'sub_title' => fake()->catchPhrase(),
            'description' => fake()->sentence(),
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('##########'),
        ];
    }
}
