<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * features-run-4 · ai-admin track routes. Owned exclusively by its track's agent —
 * parallel agents must not edit routes/web.php or another track's file.
 * Auto-required by the glob in routes/web.php. Use FQCN array actions
 * ([\App\Http\Controllers\...::class, 'method']) to avoid namespace prefixing.
 */

use Juwri\Modules\Ai\Http\Controllers\Admin\AiProvidersController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Ai/features4-ai-admin-1');
