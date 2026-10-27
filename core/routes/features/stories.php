<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * theme-pack-blade · stories feature routes (F3 Story / reel promo).
 * Owned exclusively by its feature task — parallel agents must not edit
 * any other file under routes/. Registered by the glob in routes/web.php.
 *
 * Owner admin CRUD (auth, User namespace — mirrors banners) + the public
 * customer story-viewer data endpoint.
 */

use Juwri\Modules\Storefront\Http\Controllers\User\StoryController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Storefront/features-stories-1');
