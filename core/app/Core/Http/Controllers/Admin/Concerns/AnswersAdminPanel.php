<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * JSON answers for the admin side panel and list actions: always HTTP 200
 * with `{success, message}` (see Juwri\Core\Http\Requests\Admin\PanelFormRequest).
 */
trait AnswersAdminPanel
{
    protected function panelDone(string $message): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message]);
    }

    protected function panelFailed(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message]);
    }

    /**
     * Store a drag-and-drop order: the n-th id gets position n.
     *
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     */
    protected function savePositions(string $model, array $ids, string $column = 'position'): void
    {
        DB::transaction(function () use ($model, $ids, $column) {
            foreach ($ids as $position => $id) {
                $model::query()->whereKey($id)->update([$column => $position]);
            }
        });
    }
}
