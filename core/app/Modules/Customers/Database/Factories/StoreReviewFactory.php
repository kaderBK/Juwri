<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Database\Factories;

use App\Models\StoreReview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StoreReview>
 */
class StoreReviewFactory extends Factory
{
    /** @var class-string<\Illuminate\Database\Eloquent\Model> */
    protected $model = StoreReview::class;

    public function definition(): array
    {
        return [
            'store_id' => 1,
            'reviewer_name' => fake()->firstName(),
            'stars' => fake()->numberBetween(1, 5),
            'criteria' => null,
            'body' => fake()->sentence(),
            'status' => 'pending',
            'session_hash' => hash('sha256', Str::random(16)),
            'is_verified' => false,
            'helpful_count' => 0,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved']);
    }

    /** Verified reviews are order-linked by definition — never a bare flag. */
    public function verified(int $orderId): static
    {
        return $this->state(fn () => ['is_verified' => true, 'order_id' => $orderId, 'status' => 'approved']);
    }

    public function forStore(int $storeId): static
    {
        return $this->state(fn () => ['store_id' => $storeId]);
    }
}
