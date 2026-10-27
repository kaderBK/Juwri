<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Contracts;

use Juwri\Modules\Ai\Services\AiSpendGuard;

/** P1-08 L1 public surface: the AI spend guard. Same semantics as {@see AiSpendGuard::assert()}. */
interface AiSpend
{
    /** Throws an {@see AiLimitExceeded} when the owner/store is over its AI allowance. */
    public function assert(?int $userId, ?int $storeId, bool $isByok): void;
}
