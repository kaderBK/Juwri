<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Team;

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use Illuminate\Support\Carbon;

/**
 * SYSTEMS-RUN T-P2: generate dated checklist runs from active templates and
 * track per-item completion.
 */
class ChecklistService
{
    /** Ensure a run exists for a template on a date (idempotent). */
    public function ensureRun(ChecklistTemplate $template, string $date): ChecklistRun
    {
        $existing = ChecklistRun::where('checklist_template_id', $template->id)
            ->whereDate('date', $date)
            ->when($template->branch_id === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $template->branch_id))
            ->first();
        if ($existing) {
            return $existing;
        }

        return ChecklistRun::create([
            'checklist_template_id' => $template->id,
            'date' => $date,
            'branch_id' => $template->branch_id,
            'store_id' => $template->store_id,
            'item_states' => array_map(fn ($label) => ['label' => $label, 'done' => false, 'by' => null, 'at' => null], (array) $template->items),
        ]);
    }

    /** Generate today's runs for every active template (idempotent). */
    public function generateForDate(?string $date = null, ?int $storeId = null): int
    {
        $date = $date ?: Carbon::now()->toDateString();
        $count = 0;
        ChecklistTemplate::where('active', true)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->chunkById(200, function ($templates) use ($date, &$count) {
                foreach ($templates as $t) {
                    $this->ensureRun($t, $date);
                    $count++;
                }
            });

        return $count;
    }

    /** Toggle one item's completion. */
    public function toggleItem(ChecklistRun $run, int $index, ?int $staffId, bool $done): void
    {
        $states = (array) $run->item_states;
        if (! isset($states[$index])) {
            return;
        }
        $states[$index]['done'] = $done;
        $states[$index]['by'] = $done ? $staffId : null;
        $states[$index]['at'] = $done ? now()->toDateTimeString() : null;
        $run->update(['item_states' => array_values($states)]);
    }
}
