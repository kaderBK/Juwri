<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Controllers\Rwg;

use Illuminate\Http\JsonResponse;

/**
 * GOOGLE-PARTNER Lane C — Reserve with Google booking-server HealthCheck.
 *
 * `GET /rwg/v3/HealthCheck`. Actions Center pings this to confirm the booking
 * server is reachable + authenticated; a 200 means SERVING. Auth + kill-switch are
 * enforced by RwgAuth on the route group, so reaching this method already proves
 * the credentials are valid and the integration is enabled.
 */
class HealthCheckController extends RwgController
{
    public function __invoke(): JsonResponse
    {
        return response()->json((object) [], 200);
    }
}
