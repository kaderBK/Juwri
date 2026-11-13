<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Console\Commands;

use Juwri\Modules\Menu\Models\AiMenuAction;
use Juwri\Modules\Menu\Services\Ai\MenuActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * C6 — apply due scheduled AI menu actions. A `scheduled` row whose run_at has arrived is
 * applied; a recurring one (daily|weekly) is CLONE-FORWARDED to a fresh scheduled row for the
 * next occurrence instead of becoming terminal. Each row is isolated: a failure marks that
 * row failed (and alerts the owner, via MenuActionService::apply) and never stops the rest.
 * apply() is idempotent by status, so a re-run within the hour applies nothing twice.
 */
class AiRunMenuActions extends Command
{
    protected $signature = 'ai:run-menu-actions';

    protected $description = 'Apply due scheduled AI menu actions and re-arm recurring ones.';

    public function handle(MenuActionService $service): int
    {
        $due = AiMenuAction::where('status', AiMenuAction::STATUS_SCHEDULED)
            ->whereNotNull('run_at')
            ->where('run_at', '<=', now())
            ->orderBy('run_at')
            ->get();

        $applied = 0;
        foreach ($due as $action) {
            try {
                $recurrence = $action->recurrence;
                $service->apply($action);

                if ($action->fresh()->status === AiMenuAction::STATUS_APPLIED) {
                    $applied++;
                    if (in_array($recurrence, ['daily', 'weekly'], true)) {
                        $this->reArm($action, (string) $recurrence);
                    }
                }
            } catch (\Throwable $e) {
                // Row-isolated: apply() already marks a genuine mutation failure; this catch
                // covers anything unexpected around it so one bad row can't abort the batch.
                Log::warning('ai:run-menu-actions row failed', ['action' => $action->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Applied {$applied} of {$due->count()} due AI menu action(s).");

        return self::SUCCESS;
    }

    /**
     * Clone a just-applied recurring action forward: a fresh `scheduled` draft-equivalent for
     * the next occurrence, carrying the same payload + recurrence. The applied row stays as the
     * history of this occurrence (and remains individually reversible).
     */
    private function reArm(AiMenuAction $applied, string $recurrence): void
    {
        $runAt = $applied->run_at;
        if ($runAt === null) {
            return;
        }
        $step = $recurrence === 'weekly' ? 'addWeek' : 'addDay';
        $next = $runAt->copy()->$step();
        // Skip missed occurrences after scheduler downtime: advance to the next FUTURE
        // run_at so a recurring action applies once per real period rather than firing
        // repeatedly (compounding e.g. a price_adjust) to burn down a backlog.
        $now = now();
        while ($next->lte($now)) {
            $next = $next->$step();
        }

        AiMenuAction::create([
            'store_id' => $applied->store_id,
            'kind'          => $applied->kind,
            'title'         => $applied->title,
            'payload'       => $applied->payload,
            'status'        => AiMenuAction::STATUS_SCHEDULED,
            'run_at'        => $next,
            'recurrence'    => $recurrence,
        ]);
    }
}
