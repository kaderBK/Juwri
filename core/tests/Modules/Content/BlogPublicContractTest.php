<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use Tests\TestCase;

/**
 * WI-09d characterisation of the platform's public blog (BlogController):
 * listing (published, locale scope, search, page size), single post (tags,
 * approved comments + replies, translation hreflang), tag and category pages,
 * and the comment form (guest/user/admin, the three approval modes).
 */
class BlogPublicContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['app.theme' => 'juwri']);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();

        config(['settings.blog_enable' => 1, 'settings.blog_comment_enable' => 1, 'settings.recaptcha_mode' => 0, 'settings.blog_page_limit' => 8]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function blogPost(string $title, array $attrs = []): Blog
    {
        return Blog::create($attrs + [
            'title' => $title, 'slug' => Str::slug($title), 'description' => '<p>Body of '.$title.'</p>',
            'image' => 'default.png', 'status' => 'publish', 'tags' => 'qr,menu',
        ]);
    }

    public function test_blog_is_404_when_disabled(): void
    {
        config(['settings.blog_enable' => 0]);
        $this->get(route('blog.index'))->assertNotFound();
    }

    /**
     * P1-16 regression: the blog-off gate was a constructor abort, so `artisan route:list` (which instantiates
     * every controller) crashed while the blog was off. Now a controller middleware: route:list runs, and every
     * public blog route still answers 404 when the blog is off.
     */
    public function test_route_list_runs_and_every_blog_route_is_404_when_disabled(): void
    {
        config(['settings.blog_enable' => 0]);
        $blogPost = $this->blogPost('Hidden post');

        $this->assertSame(0, Artisan::call('route:list', ['--path' => 'blog', '--json' => true]));
        $this->assertStringContainsString('blog.index', Artisan::output());

        $this->get(route('blog.index'))->assertNotFound();
        $this->get(route('blog.single', [$blogPost->id, $blogPost->slug]))->assertNotFound();
        $this->get(route('blog.tag', 'qr'))->assertNotFound();
        $this->get(route('blog.category', 'any'))->assertNotFound();
        $this->post(route('blog.comment', [$blogPost->id, $blogPost->slug]), ['comment' => 'x', 'name' => 'A', 'email' => 'a@x.test'])->assertNotFound();
        $this->assertSame(0, BlogComment::count());
    }

    public function test_index_lists_published_posts_in_the_current_locale_or_without_one(): void
    {
        $this->blogPost('Plain Post');
        $this->blogPost('English Post', ['locale' => 'en']);
        $this->blogPost('Turkish Post', ['locale' => 'tr']);
        $this->blogPost('Draft Post', ['status' => 'pending']);

        $this->get(route('blog.index'))->assertOk()
            ->assertSee('Plain Post')->assertSee('English Post')
            ->assertDontSee('Turkish Post')->assertDontSee('Draft Post');
    }

    public function test_index_search_matches_title_or_tags_and_honours_page_size(): void
    {
        $this->blogPost('Alpha Kitchen', ['tags' => 'x']);
        $this->blogPost('Beta Bar', ['tags' => 'kitchen']);
        $this->blogPost('Gamma Cafe', ['tags' => 'y']);

        $this->get(route('blog.index', ['search' => 'kitchen']))->assertOk()
            ->assertSee('Alpha Kitchen')->assertSee('Beta Bar')->assertDontSee('Gamma Cafe')
            ->assertViewHas('blogs', fn ($p) => $p->total() === 2 && str_contains($p->url(2), 'search=kitchen'));

        config(['settings.blog_page_limit' => 2]);
        $this->get(route('blog.index'))->assertViewHas('blogs', fn ($p) => $p->perPage() === 2 && $p->total() === 3
            && $p->first()->title === 'Gamma Cafe');   // newest first
    }

    public function test_single_shows_a_published_post_with_tags_and_approved_comments_only(): void
    {
        $blog = $this->blogPost('Single Post', ['tags' => 'qr,menu']);
        $top = BlogComment::create(['blog_id' => $blog->id, 'name' => 'Ann', 'email' => 'a@x.test', 'comment' => 'Visible top', 'active' => '1', 'parent' => 0]);
        BlogComment::create(['blog_id' => $blog->id, 'name' => 'Bo', 'email' => 'b@x.test', 'comment' => 'Hidden top', 'active' => '0', 'parent' => 0]);
        BlogComment::create(['blog_id' => $blog->id, 'name' => 'Cy', 'email' => 'c@x.test', 'comment' => 'Visible reply', 'active' => '1', 'parent' => $top->id]);
        BlogComment::create(['blog_id' => $blog->id, 'name' => 'Di', 'email' => 'd@x.test', 'comment' => 'Hidden reply', 'active' => '0', 'parent' => $top->id]);

        $this->get(route('blog.single', [$blog->id, $blog->slug]))->assertOk()
            ->assertSee('Single Post')
            ->assertViewHas('blog', fn ($b) => $b->tags === ['qr', 'menu'])
            ->assertViewHas('blogComments', fn ($p) => $p->total() === 1
                && $p->first()->comment === 'Visible top'
                && $p->first()->replies->pluck('comment')->all() === ['Visible reply'])
            ->assertViewHas('blogHreflang', '');

        $draft = $this->blogPost('Draft One', ['status' => 'pending']);
        $this->get(route('blog.single', [$draft->id, $draft->slug]))->assertNotFound();
    }

    public function test_single_links_published_translations_only(): void
    {
        $locales = ['en' => ['name' => 'English', 'script' => 'Latn', 'native' => 'English', 'regional' => 'en_GB'], 'tr' => ['name' => 'Turkish', 'script' => 'Latn', 'native' => 'Türkçe', 'regional' => 'tr_TR']];
        config(['laravellocalization.supportedLocales' => $locales]);
        app('laravellocalization')->setSupportedLocales($locales);

        $en = $this->blogPost('Group EN', ['locale' => 'en', 'translation_group' => 'g1']);
        $tr = $this->blogPost('Group TR', ['locale' => 'tr', 'translation_group' => 'g1']);
        $this->blogPost('Group DE draft', ['locale' => 'de', 'translation_group' => 'g1', 'status' => 'pending']);

        $this->get(route('blog.single', [$en->id, $en->slug]))->assertOk()
            ->assertViewHas('blogHreflang', function (string $tags) use ($en, $tr) {
                return substr_count($tags, '<link rel="alternate"') === 2
                    && str_contains($tags, 'hreflang="en"') && str_contains($tags, 'hreflang="tr"')
                    && str_contains($tags, '/blog/'.$en->id.'/') && str_contains($tags, '/blog/'.$tr->id.'/')
                    && ! str_contains($tags, 'hreflang="de"');
            });
    }

    public function test_tag_and_category_pages(): void
    {
        $a = $this->blogPost('Tagged Post', ['tags' => 'pizza,menu']);
        $this->blogPost('Other Post', ['tags' => 'sushi']);
        $cat = BlogCategory::create(['title' => 'News', 'slug' => 'news', 'position' => 1, 'active' => 1]);
        DB::table('blog_category_post')->insert(['category_id' => $cat->id, 'blog_id' => $a->id]);

        $this->get(route('blog.tag', 'pizza'))->assertOk()
            ->assertSee('Tagged Post')->assertDontSee('Other Post')
            ->assertViewHas('title', ___('Tag: :tag_name', ['tag_name' => 'pizza']));

        $this->get(route('blog.category', 'news'))->assertOk()
            ->assertSee('Tagged Post')->assertDontSee('Other Post')
            ->assertViewHas('title', ___('Category: :category_name', ['category_name' => 'News']));

        $this->get(route('blog.category', 'missing'))->assertNotFound();
    }

    public function test_guest_comment_needs_name_and_email_and_follows_the_approval_mode(): void
    {
        Bus::fake([CreateNotificationJob::class]);
        $blog = $this->blogPost('Comment Post');
        $url = route('blog.comment', [$blog->id, $blog->slug]);

        $this->from($url)->post($url, ['comment' => 'hi'])->assertSessionHasErrors(['user_name', 'user_email']);
        $this->assertSame(0, BlogComment::count());

        foreach (['0' => '1', '1' => '0', '2' => '0'] as $mode => $expected) {
            config(['settings.blog_comment_approval' => (int) $mode]);
            $this->from($url)->post($url, ['comment' => "guest $mode", 'user_name' => 'Guest', 'user_email' => 'g@x.test', 'comment_parent' => 0])
                ->assertRedirect($url);
            $row = BlogComment::where('comment', "guest $mode")->firstOrFail();
            $this->assertSame($expected, (string) (int) $row->active, "guest, approval mode $mode");
            $this->assertNull($row->user_id);
            $this->assertSame('Guest', $row->name);
            $this->assertSame('g@x.test', $row->email);
        }
        $this->assertSame($expected === '1' ? ___('Comment is posted.') : ___('Comment is posted, wait for the reviewer to approve.'), session('app_alert_message'));
        $this->assertSame('success', session('app_alert_type'));
        Bus::assertDispatched(CreateNotificationJob::class, fn ($job) => $job->type === 'new_comment' && $job->title === ___('New comment received'));
    }

    public function test_signed_in_comments_use_the_account_and_admins_are_auto_approved(): void
    {
        Bus::fake([CreateNotificationJob::class]);
        $blog = $this->blogPost('Member Post');
        $url = route('blog.comment', [$blog->id, $blog->slug]);
        $parent = BlogComment::create(['blog_id' => $blog->id, 'name' => 'P', 'email' => 'p@x.test', 'comment' => 'parent', 'active' => '1', 'parent' => 0]);
        $member = User::factory()->create(['name' => 'Member', 'email' => 'm@x.test', 'status' => 1, 'email_verified_at' => now()]);
        $admin = User::factory()->create(['name' => 'Boss', 'email' => 'boss@x.test', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);

        config(['settings.blog_comment_approval' => 2]);
        $this->actingAs($member)->from($url)->post($url, ['comment' => 'member says', 'comment_parent' => $parent->id])->assertRedirect($url);
        $row = BlogComment::where('comment', 'member says')->firstOrFail();
        $this->assertSame([$member->id, 'Member', 'm@x.test', '1', $parent->id], [(int) $row->user_id, $row->name, $row->email, (string) (int) $row->active, (int) $row->parent]);

        config(['settings.blog_comment_approval' => 1]);
        $this->actingAs($member)->from($url)->post($url, ['comment' => 'member moderated', 'comment_parent' => 0]);
        $this->assertSame('0', (string) (int) BlogComment::where('comment', 'member moderated')->value('active'));

        $this->actingAs($admin)->from($url)->post($url, ['comment' => 'admin says', 'comment_parent' => 0]);
        $this->assertSame('1', (string) (int) BlogComment::where('comment', 'admin says')->value('active'));
    }

    public function test_comments_disabled_or_draft_post(): void
    {
        $blog = $this->blogPost('Closed Post');
        $url = route('blog.comment', [$blog->id, $blog->slug]);

        config(['settings.blog_comment_enable' => 0]);
        $this->from($url)->post($url, ['comment' => 'x', 'user_name' => 'G', 'user_email' => 'g@x.test'])->assertRedirect($url);
        $this->assertSame(0, BlogComment::count());
        $this->assertSame('error', session('app_alert_type'));
        $this->assertSame(___('Unexpected error'), session('app_alert_message'));

        config(['settings.blog_comment_enable' => 1]);
        $draft = $this->blogPost('Draft Closed', ['status' => 'pending']);
        $this->post(route('blog.comment', [$draft->id, $draft->slug]), ['comment' => 'x', 'user_name' => 'G', 'user_email' => 'g@x.test'])->assertNotFound();
        $this->assertSame(0, BlogComment::count());
    }

    /** WI-09d hardening: unpublished posts stay off the tag and category pages. */
    public function test_tag_and_category_pages_hide_unpublished_posts(): void
    {
        $pending = $this->blogPost('Pending Tagged', ['tags' => 'pizza', 'status' => 'pending']);
        $cat = BlogCategory::create(['title' => 'Drafts', 'slug' => 'drafts', 'position' => 1, 'active' => 1]);
        DB::table('blog_category_post')->insert(['category_id' => $cat->id, 'blog_id' => $pending->id]);

        $this->get(route('blog.tag', 'pizza'))->assertOk()->assertDontSee('Pending Tagged');
        $this->get(route('blog.category', 'drafts'))->assertOk()->assertDontSee('Pending Tagged');
    }

    /** WI-09d hardening: a reply may only point at a comment on the same post; no parent means top level (was a 500). */
    public function test_reply_parent_must_belong_to_the_same_post(): void
    {
        Bus::fake([CreateNotificationJob::class]);
        $blog = $this->blogPost('Reply Post');
        $other = $this->blogPost('Other Reply Post');
        $foreign = BlogComment::create(['blog_id' => $other->id, 'name' => 'O', 'email' => 'o@x.test', 'comment' => 'elsewhere', 'active' => '1', 'parent' => 0]);
        $url = route('blog.comment', [$blog->id, $blog->slug]);
        $guest = ['user_name' => 'G', 'user_email' => 'g@x.test'];

        $this->from($url)->post($url, $guest + ['comment' => 'foreign parent', 'comment_parent' => $foreign->id])->assertRedirect($url);
        $this->from($url)->post($url, $guest + ['comment' => 'no parent'])->assertRedirect($url);
        $this->from($url)->post($url, $guest + ['comment' => 'junk parent', 'comment_parent' => 'abc'])->assertRedirect($url);

        foreach (['foreign parent', 'no parent', 'junk parent'] as $text) {
            $this->assertSame(0, (int) BlogComment::where('comment', $text)->value('parent'), $text);
        }
    }
}
