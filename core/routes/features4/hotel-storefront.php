<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Public storefront hotel endpoints for the `aurelia` theme — date-range room
 * availability + direct booking, thin wrappers over the existing hotel engine
 * (BookingService / RoomType). Auto-loaded by the features4 glob in web.php
 * BEFORE the {slug} catch-all; multi-segment paths so they can't shadow a store
 * {slug}. Array-style controller refs to avoid namespace prefixing.
 */

\Juwri\Core\Support\ModuleRoutes::slot('Hotel/features4-hotel-storefront-1');
