<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Hr module's store- and account-owned tables for the store-data registry — every table is business data of the store: follows it on transfer, never copied.
 */
final class HrStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('leave_requests'),
            StoreTable::data('leave_types'),
            StoreTable::data('payroll_items'),
            StoreTable::data('payroll_runs'),
            StoreTable::data('shift_notes'),
            StoreTable::data('shift_swaps'),
            StoreTable::data('staff_availabilities'),
            StoreTable::data('staff_documents')->files(['path' => 'local']),
            StoreTable::data('staff_onboarding_items'),
            StoreTable::data('staff_role_rates'),
            StoreTable::data('staff_shifts'),
            StoreTable::data('staff_task_completions'),
            StoreTable::data('staff_tasks'),
            StoreTable::data('staff_time_clock_entries'),
        ];
    }
}
