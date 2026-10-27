<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Lane A (Deliverect) route file — INTEGRATIONS-PACK F0-11.
 *
 * Required from web.php's "MUST precede the {slug} catch-all" block via
 * glob(__DIR__.'/integrations/*.php'), so any single-segment dashboard path
 * declared here resolves before User\StoreController@publicView. Routes run in
 * the same `web` group / namespace / scope as web.php.
 *
 * Lane A adds here: the staff busy-mode / snooze endpoints (A8). The inbound
 * Deliverect order/register/sync/cancel webhooks keep their existing routes in
 * web.php (register/sync/cancel) and the generic signed inbound path.
 * Owning a dedicated file keeps lane A out of the shared web.php.
 */

use Illuminate\Support\Facades\Route;
use Juwri\Core\Support\ModuleRoutes;

ModuleRoutes::slot('Channels/integrations-deliverect-1');
