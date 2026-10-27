<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Services\Hrm;

use App\Models\StaffMember;
use Juwri\Modules\Hr\Models\StaffOnboardingItem;

/**
 * SYSTEMS-RUN H-P1: HRM helpers — onboarding seeding + progress.
 */
class HrmService
{
    /** Seed the default onboarding checklist for a staff member (idempotent). */
    public function ensureOnboarding(StaffMember $staff): void
    {
        if (StaffOnboardingItem::where('staff_id', $staff->id)->exists()) {
            return;
        }
        foreach (StaffOnboardingItem::TEMPLATE as $i => $label) {
            StaffOnboardingItem::create([
                'staff_id' => $staff->id,
                'label' => $label,
                'position' => $i,
            ]);
        }
    }

    /** Onboarding completion 0..100. */
    public function onboardingProgress(StaffMember $staff): int
    {
        $items = StaffOnboardingItem::where('staff_id', $staff->id)->get();
        if ($items->isEmpty()) {
            return 0;
        }

        return (int) round($items->where('done', true)->count() / $items->count() * 100);
    }
}
