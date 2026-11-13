<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Lane D (UI) route file — INTEGRATIONS-PACK F0-11.
 *
 * Required from web.php's "MUST precede the {slug} catch-all" block via
 * glob(__DIR__.'/integrations/*.php'), so any single-segment dashboard path
 * declared here (e.g. /integrations) resolves before
 * User\StoreController@publicView. Routes run in the same `web` group /
 * namespace / scope as web.php.
 *
 * Lane D adds here: the owner Integrations hub (D1), the superadmin integrations
 * monitoring surface, and the reseller plan-gating surfaces. Owning a dedicated
 * file keeps lane D out of the shared web.php.
 */

use Illuminate\Support\Facades\Route;
use Juwri\Core\Support\ModuleRoutes;

ModuleRoutes::slot('Integrations/integrations-ui-1');

ModuleRoutes::slot('Channels/integrations-ui-1');
