<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * features-run-4 · AI menu actions (C5, hotel-pms platform gap). Owned by lane C.
 * Auto-required by the glob in routes/web.php. Owner dashboard surface: the AI assistant
 * SKU gates it (feature:ai_assistant, the same key as dashboard.assistant), and it carries
 * NO ROUTE_PERMISSION_MAP entry — so EnsureStaffDashboardPermission default-denies staff and
 * the screen is owner-only by design (HANDOFF note). Use FQCN array actions.
 */

use Juwri\Modules\Menu\Http\Controllers\User\AiMenuActionController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Menu/features4-ai-menu-actions-1');
