<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Content\Http\Requests\Admin\BlogCategoryRequest;
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Http\Requests\Admin\ReorderRequest;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Cviebrock\EloquentSluggable\Services\SlugService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin › Blog › Categories. The whole section is 404 while the blog is
 * switched off in the settings.
 */
class BlogCategoryController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless((bool) config('settings.blog_enable'), 404);

            return $next($request);
        });
        $this->authorizeResource(BlogCategory::class, 'category');
    }

    public function index(Request $request): View|JsonResponse
    {
        if (! $request->ajax()) {
            return view('admin.blog.categories.index');
        }

        return DataTable::make($request, [0 => 'position', 1 => 'title', 2 => 'active', 3 => 'id'], ['title'])
            ->respond(BlogCategory::query(), fn (BlogCategory $category) => [
                Cells::reorderHandle($category->id),
                Cells::text($category->title),
                Cells::status((string) $category->active === '1', ___('Active')),
                Cells::text(''), // "Created" column: blog_categories keeps no timestamps
                Cells::actions(
                    Cells::viewLink(route('blog.category', $category->slug)),
                    Cells::editLink(route('admin.blog.category.edit', $category->id)),
                ),
                Cells::checkbox($category->id),
            ]);
    }

    public function create(): View
    {
        return view('admin.blog.categories.create');
    }

    public function store(BlogCategoryRequest $request): JsonResponse
    {
        BlogCategory::create($this->attributes($request));

        return $this->panelDone(___('Created Successfully'));
    }

    public function show(BlogCategory $category): never
    {
        abort(404);
    }

    public function edit(BlogCategory $category): View
    {
        return view('admin.blog.categories.edit', ['category' => $category]);
    }

    public function update(BlogCategoryRequest $request, BlogCategory $category): JsonResponse
    {
        $category->update($this->attributes($request));

        return $this->panelDone(___('Updated Successfully'));
    }

    public function destroy(BlogCategory $category): never
    {
        abort(404);
    }

    public function reorder(ReorderRequest $request): JsonResponse
    {
        $this->authorize('reorder', BlogCategory::class);

        $this->savePositions(BlogCategory::class, $request->order());

        return $this->panelDone(___('Updated Successfully'));
    }

    /** Bulk delete; posts lose the category but are kept. */
    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        $this->authorize('deleteAny', BlogCategory::class);

        foreach (BlogCategory::query()->whereKey($request->ids())->get() as $category) {
            $category->blogs()->detach();
            $category->delete();
        }

        return $this->panelDone(___('Deleted Successfully'));
    }

    /** @return array<string, mixed> */
    private function attributes(BlogCategoryRequest $request): array
    {
        $title = (string) $request->validated('title');
        $slug = (string) $request->validated('slug');

        return [
            'title' => $title,
            'slug' => $slug !== '' ? $slug : SlugService::createSlug(BlogCategory::class, 'slug', $title),
            'active' => $request->boolean('active') ? '1' : '0',
        ];
    }
}
