<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Cviebrock\EloquentSluggable\Services\SlugService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Juwri\Modules\Content\Http\Requests\Admin\BlogPostRequest;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;

/**
 * Admin › Blog › Posts (marketing-site blog). Posts are written on a full
 * page (TinyMCE + cover image), listed through the shared DataTable and
 * removed in bulk. The section is 404 while the blog is switched off.
 */
class BlogController extends Controller
{
    use AnswersAdminPanel;

    private const IMAGE_DIR = 'storage/blog/';

    private const IMAGE_SIZE = '1300x740';

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless((bool) config('settings.blog_enable'), 404);

            return $next($request);
        });
        $this->authorizeResource(Blog::class, 'blog');
    }

    public function index(Request $request): View|JsonResponse
    {
        if (! $request->ajax()) {
            return view('admin.blog.index');
        }

        $blogPosts = Blog::query()->with('categories')->withCount('comments');

        return DataTable::make($request, [0 => 'id', 1 => 'title', 3 => 'comments_count', 4 => 'status', 5 => 'updated_at'], ['id', 'title'])
            ->respond($blogPosts, fn (Blog $blogPost) => [
                Cells::text((string) $blogPost->id),
                Cells::link(route('admin.blogs.edit', $blogPost->id), text_shorting((string) $blogPost->title, 30)),
                Cells::html($this->categoryBadges($blogPost)),
                Cells::text((string) $blogPost->getAttribute('comments_count')),
                Cells::html($blogPost->status === 'publish'
                    ? Cells::badge(___('Publish'), 'success')
                    : Cells::badge(___('Pending'), 'warning')),
                Cells::text(date_formating($blogPost->updated_at)),
                Cells::actions(
                    Cells::viewLink(route('blog.single', $blogPost->id)),
                    Cells::iconLink(route('admin.blog.comments.index').'?blog_id='.$blogPost->id, 'icon-feather-message-square', ___('Comments')),
                    Cells::iconLink(route('admin.blogs.edit', $blogPost->id), 'icon-feather-edit', ___('Edit')),
                ),
                Cells::checkbox($blogPost->id),
            ]);
    }

    public function create(): View
    {
        return view('admin.blog.create', ['categories' => $this->activeCategories()]);
    }

    public function store(BlogPostRequest $request): RedirectResponse
    {
        $image = image_upload($request->file('image'), self::IMAGE_DIR, self::IMAGE_SIZE);
        if (! $image) {
            return $this->imageFailed();
        }

        DB::transaction(function () use ($request, $image) {
            $blogPost = Blog::create($this->attributes($request) + ['image' => $image]);
            $blogPost->categories()->attach($request->categoryIds());
        });

        app_alert_success(___('Created Successfully'));

        return redirect()->route('admin.blogs.index');
    }

    public function show(Blog $blog): never
    {
        abort(404);
    }

    public function edit(Blog $blog): View
    {
        return view('admin.blog.edit', ['blog' => $blog, 'categories' => $this->activeCategories()]);
    }

    public function update(BlogPostRequest $request, Blog $blog): RedirectResponse
    {
        $image = $blog->image;
        if ($request->file('image') !== null) {
            $image = image_upload($request->file('image'), self::IMAGE_DIR, self::IMAGE_SIZE, null, $blog->image);
        }
        if (! $image) {
            return $this->imageFailed();
        }

        DB::transaction(function () use ($request, $blog, $image) {
            $blog->update($this->attributes($request) + ['image' => $image]);
            $blog->categories()->sync($request->categoryIds());
        });

        app_alert_success(___('Updated Successfully'));

        return back();
    }

    public function destroy(Blog $blog): never
    {
        abort(404);
    }

    /** Bulk delete: the posts, their category links and cover images. */
    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        $this->authorize('deleteAny', Blog::class);

        foreach (Blog::query()->whereKey($request->ids())->get() as $blogPost) {
            $blogPost->categories()->detach();
            if ($blogPost->image) {
                remove_file(self::IMAGE_DIR.$blogPost->image);
            }
            $blogPost->delete();
        }

        return $this->panelDone(___('Deleted Successfully'));
    }

    /**
     * Fields shared by create and edit. The editing admin becomes the author;
     * an empty slug is made from the title; tags are stored lower-case.
     *
     * @return array<string, mixed>
     */
    private function attributes(BlogPostRequest $request): array
    {
        $title = (string) $request->validated('title');
        $slug = (string) $request->validated('slug');

        return [
            'author' => $request->user()?->getKey(),
            'title' => $title,
            'slug' => $slug !== '' ? $slug : SlugService::createSlug(Blog::class, 'slug', $title),
            'description' => $request->validated('description'),
            'meta_description' => $request->validated('meta_description'),
            'locale' => $request->validated('locale') ?: null,
            'translation_group' => $request->validated('translation_group') ?: null,
            'tags' => mb_strtolower((string) $request->validated('tags')),
            'status' => $request->validated('status'),
        ];
    }

    /** @return Collection<int, BlogCategory> */
    private function activeCategories()
    {
        return BlogCategory::query()->where('active', '1')->get();
    }

    private function categoryBadges(Blog $blogPost): string
    {
        $badges = $blogPost->categories->map(fn (BlogCategory $category) => Cells::badge((string) $category->title, 'info'));

        return $badges->isEmpty() ? '&#8211;' : $badges->implode(' ');
    }

    private function imageFailed(): RedirectResponse
    {
        app_alert_error(___('Unable to upload the image, please try again.'));

        return back()->withInput();
    }
}
