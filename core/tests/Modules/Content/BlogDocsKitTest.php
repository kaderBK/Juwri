<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WI-16g: the blog, the owner Help Center, the changelog and the developer
 * pages render on the owner-ui marketing frame (marketing.layout) with no
 * bought-theme markup, imagery or Bootstrap, and keep their behaviour (SEO,
 * JSON-LD, comment form, guide nav, filters, feed links).
 */
class BlogDocsKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        config(['settings.blog_enable' => 1, 'settings.blog_comment_enable' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Cache::flush();
    }

    private function assertKitPage(string $html, string $label): void
    {
        $this->assertStringContainsString('mk-body', $html, "$label: kit frame");
        $this->assertStringNotContainsString('mk-body--legacy', $html, "$label: no legacy body");
        // Page body only: the shared head (partials/brand-tile) is outside this area.
        $main = preg_match('#<main[^>]*>(.*)</main>#s', $html, $m) ? $m[1] : $html;
        $this->assertStringNotContainsString('assets/templates/lamar', $main, "$label: no bought-theme assets");
        $this->assertStringNotContainsString('bootstrap', strtolower($html), "$label: no Bootstrap");
    }

    /** @return array{0: Blog, 1: BlogCategory} */
    private function makePost(): array
    {
        $author = User::factory()->create(['name' => 'Kit Author', 'status' => 1, 'email_verified_at' => now()]);
        $blog = Blog::create([
            'author' => $author->id,
            'title' => 'Kit post about QR menus',
            'slug' => 'kit-post-about-qr-menus',
            'description' => '<p>Kit body paragraph about digital menus.</p><script>alert(1)</script>',
            'image' => 'kit-hero.png',
            'tags' => 'qrmenu,ordering',
            'status' => 'publish',
        ]);
        $category = BlogCategory::create(['title' => 'Kit Category', 'slug' => 'kit-category']);
        DB::table('blog_category_post')->insert(['blog_id' => $blog->id, 'category_id' => $category->id]);

        return [$blog, $category];
    }

    public function test_blog_index_category_and_tag_pages(): void
    {
        [$blog, $category] = $this->makePost();
        $single = route('blog.single', [$blog->id, $blog->slug]);

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertKitPage($html, 'blog index');
        $this->assertStringContainsString('href="'.$single.'"', $html);
        $this->assertStringContainsString('name="search"', $html);
        $this->assertStringContainsString('storage/blog/kit-hero.png', $html);
        $this->assertStringContainsString(route('blog.category', 'kit-category'), $html, 'card links its category');

        $cat = $this->get(route('blog.category', 'kit-category'))->assertOk()->getContent();
        $this->assertKitPage($cat, 'blog category');
        $this->assertStringContainsString('Kit Category', $cat);
        $this->assertStringContainsString('href="'.$single.'"', $cat);
        $this->assertStringContainsString('BreadcrumbList', $cat);

        $tag = $this->get(route('blog.tag', 'qrmenu'))->assertOk()->getContent();
        $this->assertKitPage($tag, 'blog tag');
        $this->assertStringContainsString('href="'.$single.'"', $tag);

        $this->get(route('blog.category', 'no-such-category'))->assertNotFound();
    }

    public function test_blog_post_with_comment_form_and_structured_data(): void
    {
        [$blog] = $this->makePost();
        $comment = BlogComment::create([
            'blog_id' => $blog->id, 'name' => 'Kit Reader', 'email' => 'reader@example.test',
            'comment' => 'Kit comment body', 'active' => '1', 'parent' => 0,
        ]);
        BlogComment::create([
            'blog_id' => $blog->id, 'name' => 'Kit Replier', 'email' => 'replier@example.test',
            'comment' => 'Kit reply body', 'active' => '1', 'parent' => $comment->id,
        ]);

        $html = $this->get('/blog/'.$blog->id)->assertOk()->getContent();
        $this->assertKitPage($html, 'blog post');

        $this->assertStringContainsString('rel="canonical" href="'.route('blog.single', [$blog->id, $blog->slug]).'"', $html);
        $this->assertStringContainsString('class="mk-prose"', $html);
        $this->assertStringContainsString('Kit body paragraph about digital menus.', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'post body is sanitised');
        $this->assertStringContainsString('<time datetime="'.$blog->created_at->toDateString().'"', $html);
        $this->assertStringContainsString('Kit Author', $html);
        $this->assertStringContainsString(route('blog.tag', 'ordering'), $html);

        // Comment list (with reply) + the reply form contract.
        $this->assertStringContainsString('Kit comment body', $html);
        $this->assertStringContainsString('Kit reply body', $html);
        $this->assertStringContainsString('action="'.route('blog.comment', [$blog->id, $blog->slug]).'"', $html);
        foreach (['name="_token"', 'name="user_name"', 'name="user_email"', 'name="comment"', 'name="comment_parent"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }

        // Sidebar (view composer data): category + recent posts.
        $this->assertStringContainsString('mk-blog-side', $html);
        $this->assertStringContainsString(route('blog.category', 'kit-category'), $html);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $types = array_map(fn ($j) => json_decode($j, true)['@type'] ?? null, $m[1]);
        $this->assertContains('BlogPosting', $types);
        $this->assertContains('BreadcrumbList', $types);

        // Comments off: no form.
        config(['settings.blog_comment_enable' => 0]);
        $off = $this->get('/blog/'.$blog->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('name="comment_parent"', $off);
    }

    public function test_help_center_index_guide_and_search(): void
    {
        $index = $this->get('/help-center')->assertOk()->getContent();
        $this->assertKitPage($index, 'help index');
        $this->assertStringContainsString('href="/help-center/getting-started"', $index);
        $this->assertStringContainsString('class="mk-docs__group"', $index, 'grouped guide nav');
        $this->assertStringContainsString('action="'.url('/help-center/search').'"', $index);
        $this->assertStringNotContainsString('id="hc-helpful"', $index);

        $guide = $this->get('/help-center/getting-started')->assertOk()->getContent();
        $this->assertKitPage($guide, 'help guide');
        $this->assertStringContainsString('<h1>', $guide);
        $this->assertStringContainsString('id="hc-helpful"', $guide);
        $this->assertStringContainsString('id="docToc"', $guide);
        $this->assertStringContainsString('class="is-active" aria-current="page"', $guide, 'open guide marked in the nav');
        $this->assertStringContainsString('assets/owner-ui/site-docs.js', $guide);
        $this->assertStringContainsString('href="'.route('help.index').'"', $guide);

        $search = $this->get('/help-center/search?q=order')->assertOk()->getContent();
        $this->assertKitPage($search, 'help search');
        $this->assertStringContainsString('result', $search);
    }

    public function test_changelog_lists_entries_filters_and_feeds(): void
    {
        $html = $this->get('/changelog')->assertOk()->getContent();
        $this->assertKitPage($html, 'changelog');
        $this->assertStringContainsString('mk-changelog__entry', $html);
        $this->assertStringContainsString('href="'.url('/changelog').'.rss"', $html);
        $this->assertStringContainsString('href="'.url('/changelog').'.json"', $html);
        $this->assertStringContainsString('type="application/rss+xml"', $html);
        $this->assertStringContainsString(url('/changelog').'?product=pos', $html);

        $pos = $this->get('/changelog?product=pos')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*\?product=pos"[^>]*class="is-active"|class="is-active"[^>]*href="[^"]*\?product=pos"#', $pos);
    }

    public function test_developer_pages_and_mcp_landing_use_the_kit_frame(): void
    {
        foreach (['developers' => '.dev-docs', 'developers.apps' => '.dev-docs', 'developers.themes' => '.dev-docs', 'mcp.landing' => 'mcp-page'] as $route => $marker) {
            $html = $this->get(route($route))->assertOk()->getContent();
            $this->assertKitPage($html, $route);
            $this->assertStringContainsString('class="mk-top"', $html, "$route: kit top bar");
            $this->assertStringContainsString(ltrim($marker, '.'), $html, "$route: own content wrapper");
            $this->assertStringNotContainsString('padding-top: clamp(', $html, "$route: no fixed-header offset");
            $this->assertStringNotContainsString('btn btn-', $html, "$route: no Bootstrap buttons");
        }
    }
}
