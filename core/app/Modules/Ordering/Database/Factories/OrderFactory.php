<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Database\Factories;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'track_token' => Str::random(12),
            'customer_name' => fake()->name(),
            'phone_number' => fake()->numerify('##########'),
            'type' => 'takeaway',
            'status' => 'pending',
            'is_paid' => 0,
            'created_at' => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['is_paid' => 1, 'status' => 'completed']);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['is_paid' => 0, 'status' => 'pending']);
    }
}
