<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Ordering\Services\MealPlanService;

/**
 * theme-pack-blade F1: spawn orders for due meal-plan subscriptions (daily).
 * Idempotent per due date — each active plan with next_run <= today generates
 * one UNPAID order (reusing OrderApiService) then advances next_run by cadence.
 * Disabled restaurants (store_options meal_plans_enabled off) are a no-op.
 */
class MealsGenerateDue extends Command
{
    protected $signature = 'meals:generate-due {--date= : run as-of date (YYYY-MM-DD)}';

    protected $description = 'Generate orders for due recurring meal plans and advance their next run.';

    public function handle(MealPlanService $service): int
    {
        $created = $service->generateDue($this->option('date'));
        $this->info("Meal-plan orders generated: {$created}.");

        return self::SUCCESS;
    }
}
