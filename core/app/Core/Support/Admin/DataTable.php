<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side answer for the admin list screens (the kit data table,
 * <x-ui.data-table> + assets/owner-ui/admin.js).
 *
 * The screen sends `draw`, `start`, `length`, `order[0][column|dir]` and
 * `search[value]`; it expects `{draw, recordsTotal, recordsFiltered, data}`
 * where every data row is a list of cell HTML strings plus `DT_RowId`.
 *
 * Column indexes map to database columns through `$sortable`; an index the
 * screen marks `no-sort` (or anything unknown) falls back to the first
 * sortable column, and the direction is always normalised to asc/desc.
 */
final class DataTable
{
    private const MAX_PAGE = 500;

    /**
     * @param  array<int, string>  $sortable  column index => database column
     * @param  list<string>  $searchable  columns matched with LIKE %term%
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $sortable,
        private readonly array $searchable = [],
    ) {}

    /**
     * @param  array<int, string>  $sortable
     * @param  list<string>  $searchable
     */
    public static function make(Request $request, array $sortable, array $searchable = []): self
    {
        return new self($request, $sortable, $searchable);
    }

    public function searchTerm(): string
    {
        $search = $this->request->input('search');

        return is_array($search) ? trim((string) ($search['value'] ?? '')) : '';
    }

    /**
     * Run the query page and render each model through `$cells`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  base query (screen-level filters already applied)
     * @param  callable(TModel): list<string>  $cells
     */
    public function respond(Builder $query, callable $cells): JsonResponse
    {
        $total = (clone $query)->count();

        $term = $this->searchTerm();
        if ($term !== '' && $this->searchable !== []) {
            // the admin typed something: a 1-char term (an id like "2", a literal "%") filters, never widens to all rows
            \App\Support\SearchTerm::applyOrNone($query, $this->searchable, $term);
        }
        $filtered = $term === '' ? $total : (clone $query)->count();

        [$column, $direction] = $this->ordering();
        if ($column !== null) {
            $query->orderBy($column, $direction);
        }

        $rows = [];
        foreach ($query->offset($this->offset())->limit($this->pageSize())->get() as $model) {
            $row = $cells($model);
            $row['DT_RowId'] = $model->getKey();
            $rows[] = $row;
        }

        return response()->json([
            'draw' => (int) $this->request->input('draw', 0),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }

    /** @return array{0: string|null, 1: 'asc'|'desc'} */
    private function ordering(): array
    {
        $order = $this->request->input('order');
        $first = is_array($order) && isset($order[0]) && is_array($order[0]) ? $order[0] : [];

        $index = isset($first['column']) && is_numeric($first['column']) ? (int) $first['column'] : null;
        $column = ($index !== null && isset($this->sortable[$index]))
            ? $this->sortable[$index]
            : (array_values($this->sortable)[0] ?? null);

        $direction = strtolower((string) ($first['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return [$column, $direction];
    }

    private function offset(): int
    {
        return max(0, (int) $this->request->input('start', 0));
    }

    private function pageSize(): int
    {
        $length = (int) $this->request->input('length', 25);

        return $length < 1 ? self::MAX_PAGE : min($length, self::MAX_PAGE);
    }
}
