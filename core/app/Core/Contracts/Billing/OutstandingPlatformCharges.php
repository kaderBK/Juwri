<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

/**
 * P1-08b: does an owner still owe the platform money (a platform charge left unpaid)? Core asks this before it
 * lets a store be handed to someone else ({@see \Juwri\Core\Services\OwnershipTransferService}); the Payments module
 * owns the charges and binds the implementation.
 */
interface OutstandingPlatformCharges
{
    public function hasUnsettled(int $userId): bool;
}
