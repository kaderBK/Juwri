<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use App\Models\MenuCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Menu\Models\AiMenuAction;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Ai\MenuActionService;

/**
 * C5 — the AI menu-action screen (owner-only via feature:ai_assistant + unmapped
 * staff.dashboard). Lists drafts with a before/after preview, schedules or approves them,
 * and rolls applied ones back. All mutation goes through MenuActionService; this controller
 * only orchestrates and scopes to the current store.
 */
class AiMenuActionController extends Controller
{
    protected string $activeTheme;

    public function __construct(protected MenuActionService $service)
    {
        $this->activeTheme = active_theme();
    }

    public function index(Request $request): View
    {
        $store = dashboard_current_store();
        abort_unless($store !== null, 404, 'Pick a store first.');

        $actions = AiMenuAction::where('store_id', $store->id)->orderByDesc('id')->get();

        $previews = [];
        foreach ($actions as $action) {
            if (in_array($action->status, ['draft', 'approved', 'scheduled'], true)) {
                $previews[$action->id] = $this->preview($store->id, $action);
            }
        }

        return view('owner.ai.actions', [
            'store' => $store,
            'drafts' => $actions->whereIn('status', ['draft', 'approved'])->values(),
            'scheduled' => $actions->where('status', 'scheduled')->values(),
            'applied' => $actions->where('status', 'applied')->values(),
            'history' => $actions->whereIn('status', ['rolled_back', 'failed', 'cancelled'])->values(),
            'previews' => $previews,
        ]);
    }

    /** Propose menu changes from a prompt. Field: `prompt`. */
    public function propose(Request $request): RedirectResponse
    {
        $store = dashboard_current_store();
        abort_unless($store !== null, 404);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $created = $this->service->propose((int) $store->id, $data['prompt']);
        } catch (AiProviderFailure $e) {
            return back()->with('ai_error', ___('The assistant could not turn that into a valid menu change. Please rephrase.'));
        } catch (\Throwable $e) {
            return back()->with('ai_error', ___('The assistant is unavailable right now. Please try again.'));
        }

        if ($created->isEmpty()) {
            return back()->with('ai_error', ___('The assistant did not propose any changes. Please rephrase.'));
        }

        return back()->with('ai_success', ___('New menu changes were proposed for your review.'));
    }

    /**
     * Act on one draft/applied action. Fields: `op` (approve|schedule|cancel|rollback),
     * `run_at` (Y-m-d H:i, for schedule), `recurrence` (null|daily|weekly).
     */
    public function act(Request $request, int $action): RedirectResponse
    {
        $store = dashboard_current_store();
        abort_unless($store !== null, 404);

        $model = AiMenuAction::where('store_id', $store->id)->findOrFail($action);

        // The UI's datetime-local input sends `Y-m-d\TH:i`; normalise to the pinned
        // `Y-m-d H:i` contract (and trim any seconds) before validation.
        if ($request->filled('run_at')) {
            $request->merge(['run_at' => substr(str_replace('T', ' ', (string) $request->input('run_at')), 0, 16)]);
        }

        $data = $request->validate([
            'op' => ['required', 'in:approve,schedule,cancel,rollback'],
            'run_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'recurrence' => ['nullable', 'in:daily,weekly'],
        ]);

        switch ($data['op']) {
            case 'approve':
                $this->service->apply($model);
                $msg = $model->fresh()->status === 'applied' ? ___('Change applied.') : ___('The change could not be applied.');
                break;

            case 'schedule':
                if (empty($data['run_at'])) {
                    return back()->with('ai_error', ___('Pick a date and time to schedule this change.'));
                }
                if (in_array($model->status, ['draft', 'approved', 'scheduled'], true)) {
                    $model->status = AiMenuAction::STATUS_SCHEDULED;
                    $model->run_at = $data['run_at'];
                    $model->recurrence = $data['recurrence'] ?? null;
                    $model->save();
                    $msg = ___('Change scheduled.');
                } else {
                    $msg = ___('This change can no longer be scheduled.');
                }
                break;

            case 'cancel':
                if (in_array($model->status, ['draft', 'approved', 'scheduled'], true)) {
                    $model->status = AiMenuAction::STATUS_CANCELLED;
                    $model->save();
                    $msg = ___('Change cancelled.');
                } else {
                    $msg = ___('This change can no longer be cancelled.');
                }
                break;

            case 'rollback':
            default:
                $this->service->rollback($model);
                $msg = $model->fresh()->status === 'rolled_back' ? ___('Change rolled back.') : ___('This change could not be rolled back.');
                break;
        }

        return back()->with('ai_success', $msg);
    }

    /**
     * A before/after preview of a proposed action, from its payload and the live menu rows.
     *
     * @return array<int, array{label: string, before: string, after: string}>
     */
    private function preview(int $storeId, AiMenuAction $action): array
    {
        $payload = $action->payload ?? [];
        $rows = [];

        switch ($action->kind) {
            case 'hide_items':
            case 'show_items':
                foreach ($this->items($storeId, $payload['item_ids'] ?? []) as $item) {
                    $rows[] = [
                        'label' => (string) $item->name,
                        'before' => (string) $item->active === '1' ? ___('Visible') : ___('Hidden'),
                        'after' => $action->kind === 'show_items' ? ___('Visible') : ___('Hidden'),
                    ];
                }
                break;

            case 'price_adjust':
                $pct = (float) ($payload['percent'] ?? 0);
                foreach ($this->items($storeId, $payload['item_ids'] ?? []) as $item) {
                    $rows[] = [
                        'label' => (string) $item->name,
                        'before' => number_format((float) $item->price, 2),
                        'after' => number_format(round((float) $item->price * (1 + $pct / 100), 2), 2),
                    ];
                }
                break;

            case 'update_descriptions':
                $byId = [];
                foreach (($payload['items'] ?? []) as $row) {
                    if (is_array($row) && isset($row['id'])) {
                        $byId[(int) $row['id']] = (string) ($row['description'] ?? '');
                    }
                }
                foreach ($this->items($storeId, array_keys($byId)) as $item) {
                    $rows[] = [
                        'label' => (string) $item->name,
                        'before' => Str::limit((string) $item->description, 48),
                        'after' => Str::limit($byId[$item->id] ?? '', 48),
                    ];
                }
                break;

            case 'toggle_category':
                $cat = MenuCategory::where('store_id', $storeId)->find((int) ($payload['category_id'] ?? 0));
                if ($cat) {
                    $rows[] = [
                        'label' => (string) $cat->name,
                        'before' => (string) $cat->active === '1' ? ___('Shown') : ___('Hidden'),
                        'after' => ! empty($payload['active']) ? ___('Shown') : ___('Hidden'),
                    ];
                }
                break;
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, Menu>
     */
    private function items(int $storeId, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return Menu::whereRaw('1 = 0')->get();
        }

        return Menu::where('store_id', $storeId)->whereIn('id', $ids)->get();
    }
}
