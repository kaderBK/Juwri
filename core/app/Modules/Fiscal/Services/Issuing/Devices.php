<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Issuing;

use Illuminate\Support\Collection;
use Juwri\Modules\Fiscal\Models\FiscalDevice;

/** P4-01: the device that issues for a sale — the sale branch's own, else a store-wide one, else the first. */
final class Devices
{
    /** @param  Collection<int, FiscalDevice>  $devices */
    public static function pick(Collection $devices, ?int $branchId): ?FiscalDevice
    {
        return $devices->first(fn (FiscalDevice $d) => $branchId !== null && (int) $d->branch_id === $branchId)
            ?? $devices->first(fn (FiscalDevice $d) => $d->branch_id === null)
            ?? $devices->first();
    }
}
