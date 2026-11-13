<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

use Juwri\Modules\Reservations\Services\Rwg\Contracts\RwgWaitlistRtuClient;

/**
 * google-partner Lane D (D4): resolve the waitlist RTU client. A container
 * binding wins when present (F0 binds Lane C's C6 real client); otherwise the
 * no-network Fake keeps the dark path inert.
 */
class RwgWaitlistRtuClientFactory
{
    public static function make(): RwgWaitlistRtuClient
    {
        if (app()->bound(RwgWaitlistRtuClient::class)) {
            return app(RwgWaitlistRtuClient::class);
        }

        return new FakeRwgWaitlistRtuClient();
    }
}
