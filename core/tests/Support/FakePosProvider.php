<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\Order;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosPushResult;

/**
 * POS bridge test double whose pushOrder succeeds. Lives outside the TestCase
 * namespace so Pint's php_unit_method_casing does not snake-case testConnection()
 * — an interface method that MUST stay camelCase.
 */
class FakePosProvider implements PosBridgeProvider
{
    /** @var list<int> */
    public array $pushed = [];

    public function key(): string
    {
        return 'fake_pos';
    }

    public function label(): string
    {
        return 'Fake POS';
    }

    public function testConnection(PosBridgeConnection $c): bool
    {
        return true;
    }

    public function pushOrder(Order $o, PosBridgeConnection $c): PosPushResult
    {
        $this->pushed[] = $o->id;

        return PosPushResult::success('EXT-'.$o->id);
    }

    public function listItems(PosBridgeConnection $c): array
    {
        return [['external_id' => 'sq1', 'name' => 'Latte']];
    }
}
