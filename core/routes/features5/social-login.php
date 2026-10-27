<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * FEATURES-5 Track A — superadmin platform social-login keys.
 * Owned exclusively by Track A. Auto-required by the features5 glob in web.php.
 * FQCN array actions avoid namespace prefixing.
 */

use Juwri\Modules\Integrations\Http\Controllers\Admin\SocialProvidersController;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Integrations/features5-social-login-1');
