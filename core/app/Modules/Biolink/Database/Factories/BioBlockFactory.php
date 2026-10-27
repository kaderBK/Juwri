<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Database\Factories;

use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Core\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BioBlock> */
class BioBlockFactory extends Factory
{
    protected $model = BioBlock::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'branch_id' => null,
            'type' => 'link',
            'label' => fake()->words(2, true),
            'url' => fake()->url(),
            'config' => [],
            'position' => 0,
            'active' => true,
            'visible_from' => null,
            'visible_until' => null,
            'clicks_cached' => 0,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
