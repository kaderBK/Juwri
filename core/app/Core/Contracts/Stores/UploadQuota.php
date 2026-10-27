<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Stores;

/**
 * P6-320: the per-owner storage quota as Core sees it (Core may not import Media). Bound by the Media module
 * to its public API, the same counters as Juwri\Modules\Media\Contracts\StorageQuotas.
 */
interface UploadQuota
{
    public function add(int $userId, int $bytes): void;

    public function sub(int $userId, int $bytes): void;

    /** Whether the owner may store $incomingBytes more. */
    public function allow(int $userId, int $incomingBytes): bool;
}
