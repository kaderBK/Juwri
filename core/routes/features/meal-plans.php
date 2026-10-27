<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * theme-pack-blade · meal-plans feature routes.
 * Owned exclusively by its feature task — parallel agents must not edit
 * any other file under routes/. Registered by the glob in routes/web.php.
 *
 * F1: recurring meal-plan subscriptions. Customer-authed via CustomerTokenAuth
 * (same guard as /api/v1/customer/*), so every action is scoped to the token's
 * customer + restaurant.
 */

use Juwri\Modules\Ordering\Http\Controllers\Api\V1\MealPlanController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Ordering/features-meal-plans-1');
