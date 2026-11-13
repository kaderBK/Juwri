<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\Order;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Services\PosBridge\PosBridgeProvider;
use Juwri\Modules\Channels\Services\PosBridge\PosPushResult;

/**
 * WI-M5 test double: a POS provider whose pushOrder always fails. Lives outside
 * the TestCase namespace so Pint's php_unit_method_casing does not snake-case
 * testConnection() (an interface method that MUST stay camelCase).
 */
class FailingPosProvider implements PosBridgeProvider
{
    public int $calls = 0;

    public function __construct(private string $error = 'HTTP 500') {}

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
        $this->calls++;

        return PosPushResult::failure($this->error);
    }

    public function listItems(PosBridgeConnection $c): array
    {
        return [];
    }
}
