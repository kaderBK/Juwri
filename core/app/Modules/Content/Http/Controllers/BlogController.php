<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use App\Services\FeatureRegistry;
use App\Support\SearchTerm;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Contracts\Tenancy\ResellerSite;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * The platform's public blog: listing (with search), a post with its approved
 * comments, tag and category pages, and the comment form.
 *
 * RESELLER-PAGES R4: on a reseller domain with feature:reseller_blog, /blog and
 * /blog/{slug} are the reseller's own blog (ResellerSite, the Reseller module), which is
 * available even when the platform blog (settings.blog_enable) is off.
 */
class BlogController extends Controller
{
    public function __construct()
    {
        /* P1-16: the blog-off 404 is a controller middleware, not a constructor abort — `artisan route:list`
           instantiates every controller and stopped here when the blog was off. Same 404 for every action. */
        $this->middleware(function (Request $request, \Closure $next) {
            abort_unless($this->servesResellerBlog() || config('settings.blog_enable'), 404);

            return $next($request);
        });
        $this->activeTheme = active_theme();
    }

    /** Newest published posts in the current language (plus legacy posts without one), optionally searched. */
    public function index(Request $request): mixed
    {
        if ($this->onResellerHost()) {
            return app(ResellerSite::class)->blogIndex($request);
        }

        // rev/seo R19: current-locale posts + legacy (locale NULL) posts.
        $query = $this->published()->with(['user', 'categories'])
            ->where(fn (Builder $q) => $q->where('locale', get_lang())->orWhereNull('locale'));

        $term = $request->input('search');
        if (is_string($term) && $term !== '') {
            $query->where(fn (Builder $q) => SearchTerm::apply($q, ['title', 'tags'], $term));
        }

        $blogs = $query->orderByDesc('id')->paginate($this->perPage());
        if (is_string($term) && $term !== '') {
            $blogs->appends(['search' => $term]);
        }

        return view('marketing.blog.index', compact('blogs'));
    }

    /** One published post with its approved top-level comments (20 per page, newest first) and approved replies. */
    public function single(Request $request, $id): mixed
    {
        // On a reseller domain the {id} segment carries the reseller post's slug.
        if ($this->onResellerHost()) {
            return app(ResellerSite::class)->blogShow($request, (string) $id);
        }

        $blog = $this->published()->findOrFail($id);
        $blog->tags = explode(',', (string) $blog->tags);

        $blogComments = BlogComment::query()
            ->where('blog_id', $blog->id)
            ->where('parent', 0)
            ->where('active', '1')
            ->with(['replies' => fn ($replies) => $replies->where('active', '1')->orderByDesc('created_at')])
            ->orderByDesc('created_at')
            ->paginate(20);

        // rev/seo R19: hreflang only across true translations of this post.
        $blogHreflang = $this->translationLinks($blog);

        return view('marketing.blog.single', compact('blog', 'blogComments', 'blogHreflang'));
    }

    /** Published posts whose tag list contains `$slug`. */
    public function tag($slug): mixed
    {
        // A reseller host lists the reseller's own posts with this tag (upstream c623b24e3).
        if ($this->onResellerHost()) {
            return app(ResellerSite::class)->blogIndex(request(), (string) $slug);
        }

        $blogs = $this->published()->with(['user', 'categories'])
            ->tap(fn ($b) => SearchTerm::applyOrNone($b, ['tags'], (string) $slug))
            ->orderByDesc('id')
            ->paginate($this->perPage());

        $title = ___('Tag: :tag_name', ['tag_name' => $slug]);

        return view('marketing.blog.index', compact('title', 'blogs'));
    }

    /** Published posts in the category with this slug (404 when there is none). */
    public function category($slug): View
    {
        $category = BlogCategory::query()->where('slug', $slug)->firstOrFail();

        $blogs = $category->blogs()->where('blog_posts.status', 'publish')->with(['user', 'categories'])
            ->orderByDesc('id')
            ->paginate($this->perPage());

        $title = ___('Category: :category_name', ['category_name' => $category->title]);

        return view('marketing.blog.index', compact('title', 'blogs'));
    }

    /**
     * Store a comment (or a reply) on a published post and notify the admins.
     * Signed-in authors comment under their account; guests give a name and
     * email. Whether it shows at once depends on settings.blog_comment_approval.
     */
    public function comment(Request $request, $id): RedirectResponse
    {
        if (! config('settings.blog_comment_enable')) {
            app_alert_error(___('Unexpected error'));

            return back();
        }

        $author = $request->user();
        $rules = ['comment' => ['required', 'string']];
        if (! $author) {
            $rules += ['user_name' => ['required', 'string'], 'user_email' => ['required', 'email']];
        }
        $request->validate($rules + validate_recaptcha());

        $blog = $this->published()->findOrFail($id);
        $visible = $this->publishesDirectly($author);

        BlogComment::create([
            'blog_id' => $blog->id,
            'user_id' => $author?->id,
            'name' => $author ? $author->name : $request->input('user_name'),
            'email' => $author ? $author->email : $request->input('user_email'),
            'comment' => $request->input('comment'),
            'active' => $visible ? '1' : '0',
            'parent' => $this->replyTo($blog, $request->input('comment_parent')),
        ]);

        create_notification(___('New comment received'), 'new_comment', route('admin.blog.comments.index'));

        app_alert_success($visible
            ? ___('Comment is posted.')
            : ___('Comment is posted, wait for the reviewer to approve.'));

        return back();
    }

    /**
     * Whether a new comment is visible without review. Admins always; otherwise
     * by settings.blog_comment_approval: 1 = every comment is reviewed,
     * 2 = only guests are reviewed, anything else = nothing is reviewed.
     */
    private function publishesDirectly(?User $author): bool
    {
        if ($author !== null && $author->isAdmin()) {
            return true;
        }

        return match ((int) config('settings.blog_comment_approval')) {
            1 => false,
            2 => $author !== null,
            default => true,
        };
    }

    /** The parent comment id for a reply: only a comment on the same post counts; anything else is top level (0). */
    private function replyTo(Blog $blog, mixed $parentId): int
    {
        if (! is_numeric($parentId) || (int) $parentId <= 0) {
            return 0;
        }

        $exists = BlogComment::query()->where('blog_id', $blog->id)->whereKey((int) $parentId)->exists();

        return $exists ? (int) $parentId : 0;
    }

    /**
     * `<link rel="alternate">` tags for the published translations of a post
     * (its translation_group siblings with a locale). Empty for a post that
     * has fewer than two such versions.
     */
    private function translationLinks(Blog $blog): string
    {
        if (empty($blog->translation_group)) {
            return '';
        }

        $versions = $this->published()
            ->where('translation_group', $blog->translation_group)
            ->whereNotNull('locale')
            ->get(['id', 'slug', 'locale']);

        if ($versions->count() < 2) {
            return '';
        }

        return $versions
            ->map(function (Blog $version) {
                $href = LaravelLocalization::getLocalizedURL(
                    $version->locale, route('blog.single', [$version->id, $version->slug]), [], false
                );

                return $href ? '<link rel="alternate" hreflang="'.e($version->locale).'" href="'.e($href).'" />' : null;
            })
            ->filter()
            ->implode("\n    ");
    }

    /** @return Builder<Blog> */
    private function published(): Builder
    {
        return Blog::query()->where('status', 'publish');
    }

    private function perPage(): int
    {
        return (int) config('settings.blog_page_limit', 8);
    }

    /**
     * A reseller's domain never falls back to the platform blog (upstream
     * c623b24e3): its /blog pages are the reseller's, and 404 while the
     * reseller blog is off.
     */
    private function onResellerHost(): bool
    {
        return app(ResellerContext::class)->forRequest() !== null;
    }

    private function servesResellerBlog(): bool
    {
        return app(ResellerContext::class)->forRequest() !== null && FeatureRegistry::isEnabled('reseller_blog');
    }
}
