<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Services\Accounting;




/**
 * GAPS-RUN-2 B3-P1: accounting provider registry.
 */
class AccountingRegistry
{
    public static function get(string $key): AccountingProvider
    {
        return match ($key) {
            'xero'       => new XeroProvider(),
            default      => new QuickBooksProvider(),
        };
    }

    /** @return array<string> */
    public static function keys(): array
    {
        return ['quickbooks', 'xero'];
    }
}
