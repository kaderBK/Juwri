<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Contracts;

use Juwri\Core\Contracts\Stores\UploadQuota;
use Juwri\Modules\Media\Services\StorageQuota;

/**
 * P1-08 L1 public surface: per-owner storage quota counters, for modules above Media (Billing, Ordering). Same
 * semantics as {@see StorageQuota} (bound to Media's PublicApi).
 */
interface StorageQuotas extends UploadQuota
{
    /** @return array<int, int> owner id => tracked bytes */
    public function all(): array;

    public function add(int $userId, int $bytes): void;

    public function sub(int $userId, int $bytes): void;

    /** Whether the owner may store $incomingBytes more. */
    public function allow(int $userId, int $incomingBytes): bool;
}
