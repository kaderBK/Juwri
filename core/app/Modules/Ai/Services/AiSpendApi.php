<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

use Juwri\Modules\Ai\Contracts\AiSpend;

/** P1-08 L1 public surface: {@see AiSpend} over the static {@see AiSpendGuard}. Pure delegation. */
final class AiSpendApi implements AiSpend
{
    public function assert(?int $userId, ?int $storeId, bool $isByok): void
    {
        AiSpendGuard::assert($userId, $storeId, $isByok);
    }
}
