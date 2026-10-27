<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

use App\Models\WaitlistEntry;
use Juwri\Modules\Reservations\Services\Rwg\Contracts\RwgWaitlistRtuClient;

/**
 * google-partner Lane D (D4): no-network Fake RTU client. Records pushes so tests
 * can assert them; performs zero HTTP, keeping the dark path inert. F0 replaces it
 * with Lane C's C6 real client (service-account JWT push).
 */
class FakeRwgWaitlistRtuClient implements RwgWaitlistRtuClient
{
    /** @var list<array{entry_id:int, state:string}> */
    public array $pushed = [];

    public function push(WaitlistEntry $entry, string $googleState): void
    {
        $this->pushed[] = ['entry_id' => (int) $entry->id, 'state' => $googleState];
    }
}
