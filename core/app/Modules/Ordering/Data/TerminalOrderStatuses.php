<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Data;

use Juwri\Modules\Ordering\Enums\OrderStatus;

/**
 * The status values that end an order's active workflow, for other modules (a public part, so they need not reach into
 * Ordering's Enums folder). Single source of truth: OrderStatus::isTerminal().
 */
final class TerminalOrderStatuses
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_values(array_map(
            fn (OrderStatus $s): string => $s->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $s): bool => $s->isTerminal()),
        ));
    }
}
