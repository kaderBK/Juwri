<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

/**
 * P1-13: a confirmed payment that only an ee module can credit (a shopper-wallet top-up: Marketplace; a reseller
 * top-up, tier or wholesale invoice: Reseller; a plan, add-on, app or platform-wallet purchase: Billing) arrived while
 * that module is not active. It is left unsettled (nothing
 * claimed, no receipt; the caller's DB transaction rolls back) so it can be settled once the module is back.
 */
final class SettlementHandlerMissing extends \RuntimeException
{
    public function __construct(string $message, public readonly string $module)
    {
        parent::__construct($message);
    }
}
