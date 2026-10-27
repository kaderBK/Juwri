<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Ai;

use App\Models\MenuCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Menu\Models\AiMenuAction;
use Juwri\Modules\Menu\Models\Menu;

/**
 * C4 — the agentic menu-action engine. propose() grounds the model in THIS store's menu
 * and turns a natural-language instruction into validated draft actions; apply() snapshots
 * the affected rows and mutates them; rollback() restores the snapshot. apply/rollback are
 * idempotent by status, and any failure lands the action in `failed` with an owner alert.
 *
 * The provider is reached only through AiService::json (which carries the AiSpendGuard), so
 * spend is metered on propose() alone — apply/rollback never call the model.
 */
class MenuActionService
{
    public function __construct(protected Ai $ai) {}

    /**
     * Turn a prompt into validated draft actions for a store. The AiService validator rejects
     * any proposal whose shape, kind, or payload is invalid (foreign item/category ids, an
     * over-cap price move, a bad kind) and retries the model; a proposal that never validates
     * throws AiProviderException. Returns the created draft rows.
     *
     * @return Collection<int, AiMenuAction>
     */
    public function propose(int $storeId, string $prompt): Collection
    {
        $store = Store::find($storeId);
        if (! $store) {
            return collect();
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($storeId)],
            ['role' => 'user', 'content' => mb_substr(trim($prompt), 0, 1000)],
        ];

        $decoded = $this->ai->json(
            $messages,
            ['max_tokens' => 900],
            fn (array $d): bool => $this->validateProposal($d, $storeId),
            (int) ($store->user_id ?? 0),
            $storeId,
            'menu_actions'
        );

        $created = collect();
        foreach ($decoded['actions'] as $action) {
            $created->push(AiMenuAction::create([
                'store_id' => $storeId,
                'kind' => $action['kind'],
                'title' => mb_substr((string) $action['title'], 0, 120),
                'payload' => $action['payload'],
                'status' => AiMenuAction::STATUS_DRAFT,
            ]));
        }

        return $created;
    }

    /**
     * Apply an action: snapshot the affected rows, mutate them, mark applied. Idempotent —
     * an already-applied action is a no-op. A failure rolls the transaction back, marks the
     * action failed with the error, and alerts the owner (type `ai_action`).
     */
    public function apply(AiMenuAction $action): AiMenuAction
    {
        if ($action->status === AiMenuAction::STATUS_APPLIED) {
            return $action; // idempotent
        }
        if (! in_array($action->status, [AiMenuAction::STATUS_DRAFT, AiMenuAction::STATUS_APPROVED, AiMenuAction::STATUS_SCHEDULED], true)) {
            return $action; // only pre-apply states are applicable
        }

        try {
            DB::transaction(function () use ($action) {
                $snapshot = $this->mutate($action);
                $action->snapshot = $snapshot;
                $action->status = AiMenuAction::STATUS_APPLIED;
                $action->applied_at = now();
                $action->error = null;
                $action->save();
            });
        } catch (\Throwable $e) {
            $this->markFailed($action, $e);
        }

        return $action->refresh();
    }

    /**
     * Roll an applied action back: restore the snapshot, mark rolled_back. Idempotent — an
     * already-rolled-back action is a no-op, and only an applied action can be reversed.
     */
    public function rollback(AiMenuAction $action): AiMenuAction
    {
        if ($action->status === AiMenuAction::STATUS_ROLLED_BACK) {
            return $action; // idempotent
        }
        if ($action->status !== AiMenuAction::STATUS_APPLIED) {
            return $action; // only an applied action can be rolled back
        }

        try {
            DB::transaction(function () use ($action) {
                $this->restore($action->snapshot ?? []);
                $action->status = AiMenuAction::STATUS_ROLLED_BACK;
                $action->error = null;
                $action->save();
            });
        } catch (\Throwable $e) {
            $this->markFailed($action, $e);
        }

        return $action->refresh();
    }

    /* ------------------------------------------------------------------ apply */

    /**
     * Perform the kind's mutation and return the pre-apply snapshot.
     *
     * @return array{items: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>}
     */
    private function mutate(AiMenuAction $action): array
    {
        $rid = (int) $action->store_id;
        $payload = $action->payload ?? [];
        $snapshot = ['items' => [], 'categories' => []];

        switch ($action->kind) {
            case 'hide_items':
            case 'show_items':
                $target = $action->kind === 'show_items' ? '1' : '0';
                foreach ($this->storeItems($rid, $payload['item_ids'] ?? []) as $item) {
                    $snapshot['items'][] = ['id' => $item->id, 'active' => $item->active];
                    $item->active = $target;
                    $item->save();
                }
                break;

            case 'price_adjust':
                $pct = (float) ($payload['percent'] ?? 0);
                foreach ($this->storeItems($rid, $payload['item_ids'] ?? []) as $item) {
                    $snapshot['items'][] = ['id' => $item->id, 'price' => $item->price];
                    $item->price = round((float) $item->price * (1 + $pct / 100), 2);
                    $item->save();
                }
                break;

            case 'update_descriptions':
                $byId = [];
                foreach (($payload['items'] ?? []) as $row) {
                    if (is_array($row) && isset($row['id'])) {
                        $byId[(int) $row['id']] = (string) ($row['description'] ?? '');
                    }
                }
                foreach ($this->storeItems($rid, array_keys($byId)) as $item) {
                    $snapshot['items'][] = ['id' => $item->id, 'description' => $item->description];
                    $item->description = $byId[$item->id] ?? $item->description;
                    $item->save();
                }
                break;

            case 'toggle_category':
                $cat = MenuCategory::where('store_id', $rid)
                    ->where('id', (int) ($payload['category_id'] ?? 0))
                    ->first();
                if ($cat) {
                    $snapshot['categories'][] = ['id' => $cat->id, 'active' => $cat->active];
                    $cat->active = ! empty($payload['active']) ? '1' : '0';
                    $cat->save();
                }
                break;
        }

        return $snapshot;
    }

    /** @param array{items?: array<int, array<string, mixed>>, categories?: array<int, array<string, mixed>>} $snapshot */
    private function restore(array $snapshot): void
    {
        foreach (($snapshot['items'] ?? []) as $row) {
            $item = Menu::find($row['id'] ?? 0);
            if (! $item) {
                continue;
            }
            if (array_key_exists('active', $row)) {
                $item->active = $row['active'];
            }
            if (array_key_exists('price', $row)) {
                $item->price = $row['price'];
            }
            if (array_key_exists('description', $row)) {
                $item->description = $row['description'];
            }
            $item->save();
        }
        foreach (($snapshot['categories'] ?? []) as $row) {
            $cat = MenuCategory::find($row['id'] ?? 0);
            if ($cat && array_key_exists('active', $row)) {
                $cat->active = $row['active'];
                $cat->save();
            }
        }
    }

    /**
     * Menu items of the store from a list of candidate ids — the ownership re-check at apply
     * time (belt and braces with the propose-time validator).
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, Menu>
     */
    private function storeItems(int $storeId, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return collect();
        }

        return Menu::where('store_id', $storeId)->whereIn('id', $ids)->get();
    }

    private function markFailed(AiMenuAction $action, \Throwable $e): void
    {
        $action->status = AiMenuAction::STATUS_FAILED;
        $action->error = mb_substr($e->getMessage(), 0, 255);
        $action->save();

        try {
            $title = ___('AI menu action failed').': '.$this->escape((string) $action->title);
            CreateNotificationJob::dispatch($title, 'ai_action', null)->afterCommit();
        } catch (\Throwable $inner) {
            Log::warning('ai menu action failure notif dispatch failed', ['action' => $action->id, 'error' => $inner->getMessage()]);
        }
    }

    /* ------------------------------------------------------------- validation */

    /**
     * The AiService::json validator: the whole proposal must be {actions:[{kind,title,payload}]},
     * every kind whitelisted and every payload valid for its kind against THIS store's rows.
     *
     * @param  array<string, mixed>  $decoded
     */
    public function validateProposal(array $decoded, int $storeId): bool
    {
        if (! isset($decoded['actions']) || ! is_array($decoded['actions']) || $decoded['actions'] === []) {
            return false;
        }

        $itemIds = Menu::where('store_id', $storeId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $catIds = MenuCategory::where('store_id', $storeId)->pluck('id')->map(fn ($i) => (int) $i)->all();

        foreach ($decoded['actions'] as $action) {
            if (! is_array($action)) {
                return false;
            }
            $kind = $action['kind'] ?? null;
            if (! in_array($kind, AiMenuAction::KINDS, true)) {
                return false;
            }
            if (! isset($action['title']) || ! is_string($action['title']) || trim($action['title']) === '') {
                return false;
            }
            $payload = $action['payload'] ?? null;
            if (! is_array($payload) || ! $this->validatePayload((string) $kind, $payload, $itemIds, $catIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $itemIds
     * @param  array<int, int>  $catIds
     */
    private function validatePayload(string $kind, array $payload, array $itemIds, array $catIds): bool
    {
        switch ($kind) {
            case 'hide_items':
            case 'show_items':
                return $this->allItemsBelong($payload['item_ids'] ?? null, $itemIds);

            case 'price_adjust':
                if (! $this->allItemsBelong($payload['item_ids'] ?? null, $itemIds)) {
                    return false;
                }
                $pct = $payload['percent'] ?? null;

                return is_numeric($pct) && abs((float) $pct) <= 50; // ±50% cap

            case 'update_descriptions':
                $items = $payload['items'] ?? null;
                if (! is_array($items) || $items === []) {
                    return false;
                }
                foreach ($items as $row) {
                    if (! is_array($row) || ! isset($row['id']) || ! in_array((int) $row['id'], $itemIds, true)) {
                        return false;
                    }
                    if (! isset($row['description']) || ! is_string($row['description'])) {
                        return false;
                    }
                }

                return true;

            case 'toggle_category':
                $cid = $payload['category_id'] ?? null;

                return $cid !== null && in_array((int) $cid, $catIds, true) && array_key_exists('active', $payload);
        }

        return false;
    }

    /**
     * @param  mixed  $ids
     * @param  array<int, int>  $itemIds
     */
    private function allItemsBelong($ids, array $itemIds): bool
    {
        if (! is_array($ids) || $ids === []) {
            return false;
        }

        return array_all($ids, fn ($id) => in_array((int) $id, $itemIds, true));
    }

    private function systemPrompt(int $storeId): string
    {
        $items = Menu::where('store_id', $storeId)
            ->get(['id', 'name', 'price_minor', 'currency_code', 'category_id'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => (string) $m->name, 'price' => (float) $m->price, 'category_id' => (int) $m->category_id])
            ->all();
        $cats = MenuCategory::where('store_id', $storeId)
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
            ->all();

        return 'You propose menu changes for ONE restaurant. Reply with ONLY a JSON object '
            .'{"actions":[{"kind":..., "title":..., "payload":...}]}. '
            .'Allowed kinds: hide_items|show_items (payload {"item_ids":[...]}), '
            .'price_adjust (payload {"item_ids":[...], "percent": number between -50 and 50}), '
            .'update_descriptions (payload {"items":[{"id":.., "description":".."}]}), '
            .'toggle_category (payload {"category_id":.., "active": true|false}). '
            .'Use ONLY these item ids: '.json_encode($items).'. Categories: '.json_encode($cats).'. '
            .'Never invent ids. Keep price moves within ±50%.';
    }

    /** Escape markdown link control chars in a dynamic notification title. */
    private function escape(string $text): string
    {
        return str_replace(['[', ']', '(', ')'], ['\\[', '\\]', '\\(', '\\)'], $text);
    }
}
