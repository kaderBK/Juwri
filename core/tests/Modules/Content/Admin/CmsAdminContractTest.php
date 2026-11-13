<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Juwri\Core\Models\Language;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use Juwri\Modules\Content\Models\Page;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * WI-08 characterisation: blog posts, CMS pages and languages (Community).
 * Written against the pre-rewrite controllers; the rewrite must keep it green.
 */
class CmsAdminContractTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    /** Language code used for the file-system tests; its folder is removed afterwards. */
    private const LANG = 'zq';

    /** @var list<string> */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
        config(['settings.blog_enable' => 1, 'settings.lang' => 'en']);
        Language::query()->where('code', '!=', 'en')->delete(); // migrations seed a few
        File::deleteDirectory(base_path('lang/'.self::LANG));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('lang/'.self::LANG));
        foreach ($this->uploads as $file) {
            remove_file('storage/blog/'.$file);
        }
        parent::tearDown();
    }

    // ── Blog posts ─────────────────────────────────────────────────────────

    private function category(string $title = 'News', string $active = '1'): BlogCategory
    {
        return BlogCategory::create(['title' => $title, 'slug' => str($title)->slug().'-'.uniqid(), 'active' => $active]);
    }

    private function blogPost(string $title = 'Hello', string $status = 'publish'): Blog
    {
        return Blog::create(['author' => $this->admin()->id, 'title' => $title, 'slug' => str($title)->slug().'-'.uniqid(), 'description' => 'Body', 'image' => 'keep.jpg', 'status' => $status]);
    }

    public function test_blog_posts_admin_only(): void
    {
        $blog = $this->blogPost();
        $this->assertAdminOnly('get', route('admin.blogs.index'));
        $this->assertAdminOnly('get', route('admin.blogs.create'));
        $this->assertAdminOnly('post', route('admin.blogs.store'), ['title' => 'X']);
        $this->assertAdminOnly('put', route('admin.blogs.update', $blog), ['title' => 'Changed']);
        $this->assertAdminOnly('post', route('admin.blogs.delete'), ['ids' => [$blog->id]]);
        $this->assertSame('Hello', $blog->fresh()->title);
    }

    public function test_blog_posts_are_hidden_while_the_blog_is_off(): void
    {
        config(['settings.blog_enable' => 0]);
        $this->actingAs($this->admin())->get(route('admin.blogs.index'))->assertNotFound();
    }

    public function test_blog_posts_list(): void
    {
        $admin = $this->admin();
        $news = $this->category('News');
        $first = $this->blogPost('Alpha post', 'publish');
        $first->categories()->attach($news->id);
        BlogComment::create(['blog_id' => $first->id, 'user_id' => $admin->id, 'name' => 'A', 'email' => 'a@example.com', 'comment' => 'c', 'active' => 1]);
        $this->blogPost('Beta post', 'pending');

        $this->actingAs($admin)->get(route('admin.blogs.index'))->assertOk()->assertViewIs('admin.blog.index');

        $response = $this->actingAs($admin)->dataTable(route('admin.blogs.index'), 0, 'asc')->assertOk()
            ->assertJson(['draw' => 3, 'recordsTotal' => 2]);
        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString((string) $first->id, $rows[0][0]);
        $this->assertStringContainsString('Alpha post', $rows[0][1]);
        $this->assertStringContainsString(route('admin.blogs.edit', $first->id), $rows[0][1]);
        $this->assertStringContainsString('News', $rows[0][2]);
        $this->assertStringContainsString('1', $rows[0][3]);
        $this->assertStringContainsString('data-tone="success"', $rows[0][4]);
        $this->assertStringContainsString('data-tone="warning"', $rows[1][4]);
        $this->assertStringContainsString(route('blog.single', $first->id), $rows[0][6]);
        $this->assertStringContainsString(route('admin.blog.comments.index').'?blog_id='.$first->id, $rows[0][6]);
        $this->assertStringContainsString('quick-check', $rows[0][7]);
        $this->assertSame($first->id, $rows[0]['DT_RowId']);

        $this->assertSame('Beta post', strip_tags($this->actingAs($admin)->dataTable(route('admin.blogs.index'), 1, 'desc')->json('data.0.1')));
        $found = $this->actingAs($admin)->dataTable(route('admin.blogs.index'), 0, 'asc', 'Beta')->json('data');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('Beta post', $found[0][1]);
    }

    public function test_blog_posts_create_update_delete(): void
    {
        $admin = $this->admin();
        $news = $this->category('News');
        $this->category('Hidden', '0');

        $this->actingAs($admin)->get(route('admin.blogs.create'))->assertOk()->assertViewIs('admin.blog.create')
            ->assertViewHas('categories', fn ($c) => $c->pluck('title')->all() === ['News']);

        $fields = ['title' => 'Launch Day', 'description' => '<p>Body</p>', 'status' => 'publish', 'category' => [$news->id], 'tags' => 'QR,Menu'];

        // Missing image / category / title: back with an error, nothing stored.
        $this->actingAs($admin)->from(route('admin.blogs.create'))->post(route('admin.blogs.store'), $fields)
            ->assertRedirect(route('admin.blogs.create'))->assertSessionHas('app_alert_type', 'error');
        $this->actingAs($admin)->from(route('admin.blogs.create'))->post(route('admin.blogs.store'), ['category' => null, 'image' => UploadedFile::fake()->image('a.jpg')] + $fields)
            ->assertSessionHas('app_alert_type', 'error');
        $this->actingAs($admin)->from(route('admin.blogs.create'))->post(route('admin.blogs.store'), ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')] + $fields)
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertSame(0, Blog::count());

        $this->actingAs($admin)->post(route('admin.blogs.store'), $fields + [
            'image' => UploadedFile::fake()->image('cover.jpg', 40, 20),
            'meta_description' => 'Snippet', 'locale' => 'ar', 'translation_group' => 'grp-1',
        ])->assertRedirect(route('admin.blogs.index'))->assertSessionHas('app_alert_type', 'success');

        $blog = Blog::firstOrFail();
        $this->uploads[] = (string) $blog->image;
        $this->assertSame(
            ['Launch Day', 'launch-day', 'qr,menu', 'publish', $admin->id, 'Snippet', 'ar', 'grp-1'],
            [$blog->title, $blog->slug, $blog->tags, $blog->status, (int) $blog->author, $blog->meta_description, $blog->locale, $blog->translation_group],
        );
        $this->assertNotEmpty($blog->image);
        $this->assertSame([$news->id], $blog->categories()->pluck('blog_categories.id')->all());

        // A taken slug is refused.
        $this->actingAs($admin)->from(route('admin.blogs.create'))->post(route('admin.blogs.store'), ['slug' => 'launch-day', 'image' => UploadedFile::fake()->image('b.jpg')] + $fields)
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertSame(1, Blog::count());

        $this->actingAs($admin)->get(route('admin.blogs.edit', $blog))->assertOk()->assertViewIs('admin.blog.edit')
            ->assertViewHas('blog')->assertViewHas('categories');

        $other = $this->category('Other');
        $editor = $this->admin();
        $image = $blog->image;
        $this->actingAs($editor)->from(route('admin.blogs.edit', $blog))->put(route('admin.blogs.update', $blog), [
            'title' => 'Launch Day 2', 'slug' => '', 'description' => '<p>New</p>', 'status' => 'pending',
            'category' => [$other->id], 'tags' => 'NEW', 'locale' => '', 'translation_group' => '',
        ])->assertRedirect(route('admin.blogs.edit', $blog))->assertSessionHas('app_alert_type', 'success');
        $blog->refresh();
        $this->assertSame(['Launch Day 2', 'launch-day-2', 'pending', 'new', $image, $editor->id, null, null],
            [$blog->title, $blog->slug, $blog->status, $blog->tags, $blog->image, (int) $blog->author, $blog->locale, $blog->translation_group]);
        $this->assertSame([$other->id], $blog->categories()->pluck('blog_categories.id')->all());

        // Keeping its own slug is fine; validation errors leave the post alone.
        $this->actingAs($editor)->from(route('admin.blogs.edit', $blog))->put(route('admin.blogs.update', $blog), [
            'title' => 'L', 'description' => 'x', 'status' => 'publish', 'category' => [$other->id],
        ])->assertSessionHas('app_alert_type', 'error');
        $this->assertSame('Launch Day 2', $blog->fresh()->title);

        $this->actingAs($admin)->get(route('admin.blogs.show', $blog))->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.blogs.destroy', $blog))->assertNotFound();

        $keep = $this->blogPost('Keep me');
        $this->actingAs($admin)->post(route('admin.blogs.delete'), ['ids' => [$blog->id]])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(['Keep me'], Blog::pluck('title')->all());
        $this->assertSame(0, \DB::table('blog_category_post')->where('blog_id', $blog->id)->count());
        $this->assertNotNull(BlogCategory::find($other->id), 'categories are kept');
        $this->uploads = [];
        $this->assertNotNull($keep->fresh());
    }

    // ── CMS pages ──────────────────────────────────────────────────────────

    private function page(array $overrides = []): Page
    {
        return Page::create(array_merge([
            'title' => 'About us', 'content' => '<p>About</p>', 'slug' => 'about-'.uniqid(),
            'type' => '0', 'translation_lang' => null, 'active' => 1,
        ], $overrides));
    }

    public function test_pages_admin_only(): void
    {
        $page = $this->page();
        $this->assertAdminOnly('get', route('admin.pages.index', ['lang' => 'en']));
        $this->assertAdminOnly('post', route('admin.pages.store'), ['page_title' => 'New']);
        $this->assertAdminOnly('put', route('admin.pages.update', $page), ['page_title' => 'Changed']);
        $this->assertAdminOnly('delete', route('admin.pages.destroy', $page));
        $this->assertSame('About us', $page->fresh()->title);
    }

    public function test_pages_list_per_language(): void
    {
        $admin = $this->admin();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        $this->page(['title' => 'Shared']);
        $this->page(['title' => 'English only', 'translation_lang' => 'en']);
        $this->page(['title' => 'Arabic only', 'translation_lang' => 'ar']);

        $this->actingAs($admin)->get(route('admin.pages.index'))->assertRedirect(route('admin.pages.index').'?lang=en');

        $this->actingAs($admin)->get(route('admin.pages.index', ['lang' => 'ar']))->assertOk()
            ->assertViewIs('admin.pages.index')
            ->assertViewHas('current_language', 'ar')
            ->assertViewHas('pages', fn ($pages) => $pages->pluck('title')->sort()->values()->all() === ['Arabic only', 'Shared']);

        $this->actingAs($admin)->get(route('admin.pages.index', ['lang' => 'xx']))->assertNotFound();
    }

    public function test_pages_create_update_delete(): void
    {
        $admin = $this->admin();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->actingAs($admin)->get(route('admin.pages.create'))->assertOk()->assertViewIs('admin.pages.create');

        $fields = ['page_title' => 'Terms', 'page_content' => '<p>The terms</p>', 'type' => '0', 'active' => 1];
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.pages.store'), ['page_title' => 'T'] + $fields));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.pages.store'), ['page_content' => 'abc'] + $fields));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.pages.store'), ['translation_lang' => 'xx'] + $fields));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.pages.store'), ['slug' => 'bad slug'] + $fields));
        $this->assertSame(0, Page::count());

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.pages.store'), $fields + ['translation_lang' => 'en']));
        $terms = Page::firstOrFail();
        $this->assertSame(['Terms', 'terms', '<p>The terms</p>', '0', 'en', 1], [$terms->title, $terms->slug, $terms->content, (string) $terms->type, $terms->translation_lang, (int) $terms->active]);

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.pages.store'), ['slug' => 'terms'] + $fields));

        $this->actingAs($admin)->get(route('admin.pages.edit', $terms))->assertOk()->assertViewIs('admin.pages.edit')->assertViewHas('page');

        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.pages.update', $terms), [
            'page_title' => 'Terms of use', 'slug' => 'terms', 'page_content' => '<p>New terms</p>', 'type' => '0', 'active' => 0,
        ]));
        $terms->refresh();
        $this->assertSame(['Terms of use', 'terms', '<p>New terms</p>', null, 0], [$terms->title, $terms->slug, $terms->content, $terms->translation_lang, (int) $terms->active]);
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.pages.update', $terms), ['page_title' => 'X', 'type' => '0', 'active' => 1, 'page_content' => '<p>ok ok</p>']));

        // Builder pages: the classic panel edits metadata only, never the HTML.
        $built = $this->page(['title' => 'Landing', 'content' => '<div>design</div>', 'builder' => 'html']);
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.pages.update', $built), [
            'page_title' => 'Landing 2', 'slug' => '', 'type' => '0', 'active' => 1, 'page_content' => '<p>ignored</p>',
        ]));
        $built->refresh();
        $this->assertSame(['Landing 2', 'landing-2', '<div>design</div>'], [$built->title, $built->slug, $built->content]);

        $this->actingAs($admin)->get(route('admin.pages.show', $terms))->assertNotFound();
        $this->actingAs($admin)->from(route('admin.pages.index'))->delete(route('admin.pages.destroy', $terms))
            ->assertRedirect(route('admin.pages.index'))->assertSessionHas('app_alert_type', 'success');
        $this->assertNull(Page::find($terms->id));
    }

    // ── Languages ──────────────────────────────────────────────────────────

    public function test_languages_admin_only(): void
    {
        $lang = Language::updateOrCreate(['code' => 'de'], ['name' => 'German', 'direction' => 'ltr', 'active' => 1]);
        $this->assertAdminOnly('get', route('admin.languages.index'));
        $this->assertAdminOnly('post', route('admin.languages.store'), ['name' => 'Q', 'code' => self::LANG, 'direction' => 'ltr']);
        $this->assertAdminOnly('put', route('admin.languages.update', $lang), ['name' => 'Changed', 'direction' => 'ltr', 'active' => 1]);
        $this->assertAdminOnly('delete', route('admin.languages.destroy', $lang));
        $this->assertAdminOnly('post', route('admin.languages.reorder'), ['position' => [$lang->id]]);
        $this->assertAdminOnly('post', route('admin.languages.translates.update', $lang), ['translates' => ['lang' => ['x' => 'y']]]);
        $this->assertSame('German', $lang->fresh()->name);
        $this->assertDirectoryDoesNotExist(base_path('lang/'.self::LANG));
    }

    public function test_languages_list_and_reorder(): void
    {
        $admin = $this->admin();
        $en = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $ar = Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 0]);

        $this->actingAs($admin)->get(route('admin.languages.index'))->assertOk()->assertViewIs('admin.languages.index')
            ->assertViewHas('coverage')->assertViewHas('languageNames');

        $rows = $this->actingAs($admin)->dataTable(route('admin.languages.index'), 1, 'asc')->assertOk()
            ->assertJson(['recordsTotal' => 2])->json('data');
        $this->assertStringContainsString('app-reorder-icon', $rows[0][0]);
        $this->assertStringContainsString('AR', $rows[0][1]);
        $this->assertStringContainsString('Arabic', $rows[0][2]);
        $this->assertStringContainsString('RTL', $rows[0][3]);
        $this->assertStringContainsString('data-tone="danger"', $rows[0][4]);
        $this->assertStringContainsString(route('admin.languages.edit', $ar->id), $rows[0][5]);
        $this->assertStringContainsString(route('admin.languages.translates', 'ar'), $rows[0][5]);
        $this->assertStringContainsString('action="'.route('admin.languages.destroy', $ar->id).'"', $rows[0][5]);
        $this->assertStringContainsString('data-tone="success"', $rows[1][4]);
        $this->assertStringNotContainsString('action="'.route('admin.languages.destroy', $en->id).'"', $rows[1][5], 'the default language has no delete button');
        $this->assertSame($ar->id, $rows[0]['DT_RowId']);
        $this->assertCount(1, $this->actingAs($admin)->dataTable(route('admin.languages.index'), 1, 'asc', 'Engl')->json('data'));

        $this->assertPanelOk($this->actingAs($admin)->post(route('admin.languages.reorder'), ['position' => [$ar->id, $en->id]]));
        $this->assertSame([1, 0], [(int) $en->fresh()->position, (int) $ar->fresh()->position]);
    }

    public function test_languages_create_update_translate_delete(): void
    {
        $admin = $this->admin();
        $en = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->actingAs($admin)->get(route('admin.languages.create'))->assertOk()->assertViewIs('admin.languages.create');

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.languages.store'), ['name' => 'Q', 'code' => 'q', 'direction' => 'ltr']));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.languages.store'), ['name' => 'Q', 'code' => 'en', 'direction' => 'ltr']));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.languages.store'), ['code' => self::LANG, 'direction' => 'ltr']));
        $this->assertSame(1, Language::count());

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.languages.store'), ['name' => 'Quenya', 'code' => self::LANG, 'direction' => 'rtl']));
        $lang = Language::where('code', self::LANG)->firstOrFail();
        $this->assertSame(['Quenya', 'rtl'], [$lang->name, $lang->direction]);
        $this->assertFileExists(base_path('lang/'.self::LANG.'/lang.php'), 'the default language files are copied');
        $this->assertFileExists(base_path('lang/'.self::LANG.'/validation.php'));

        $this->actingAs($admin)->get(route('admin.languages.edit', $lang))->assertOk()->assertViewIs('admin.languages.edit')->assertViewHas('language');
        $this->actingAs($admin)->get(route('admin.languages.show', $lang))->assertRedirect(route('admin.languages.edit', $lang));

        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.languages.update', $lang), ['name' => 'Q', 'direction' => 'ltr', 'active' => 'maybe']));
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.languages.update', $lang), ['name' => 'Quenya 2', 'direction' => 'ltr', 'active' => 0]));
        $lang->refresh();
        $this->assertSame(['Quenya 2', 'ltr', 0], [$lang->name, $lang->direction, (int) $lang->active]);

        // The default language cannot be switched off or lose its default flag.
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.languages.update', $en), ['name' => 'English', 'direction' => 'ltr', 'active' => 0, 'is_default' => 1]));
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.languages.update', $en), ['name' => 'English', 'direction' => 'ltr', 'active' => 1]));
        $this->assertSame(1, (int) $en->fresh()->active);

        // Making another active language the default stores it in the options.
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.languages.update', $lang), ['name' => 'Quenya 2', 'direction' => 'ltr', 'active' => 1, 'is_default' => 1]));
        $this->assertSame(self::LANG, PlatformSettings::stored('lang'));
        config(['settings.lang' => 'en']);

        // Translate screen + save.
        $this->actingAs($admin)->get(route('admin.languages.translates', self::LANG))->assertOk()->assertViewIs('admin.languages.translate')
            ->assertViewHas('translates', fn ($t) => isset($t['lang'], $t['validation']))
            ->assertViewHas('defaultLanguage')->assertViewHas('language');
        $this->actingAs($admin)->get(route('admin.languages.translates', 'nope'))->assertNotFound();

        $this->actingAs($admin)->from(route('admin.languages.translates', self::LANG))
            ->post(route('admin.languages.translates.update', $lang), ['translates' => ['pagination' => ['next' => 'Onward »', 'previous' => '« Back']]])
            ->assertRedirect(route('admin.languages.translates', self::LANG))->assertSessionHas('app_alert_type', 'success');
        $saved = include base_path('lang/'.self::LANG.'/pagination.php');
        $this->assertSame(['next' => 'Onward »', 'previous' => '« Back'], $saved);

        $this->actingAs($admin)->from(route('admin.languages.translates', self::LANG))
            ->post(route('admin.languages.translates.update', $lang), ['translates' => ['nofile' => ['a' => 'b']]])
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertFileDoesNotExist(base_path('lang/'.self::LANG.'/nofile.php'));

        // Delete: never the default language; otherwise the row and its folder go.
        $this->actingAs($admin)->from(route('admin.languages.index'))->delete(route('admin.languages.destroy', $en))
            ->assertRedirect(route('admin.languages.index'))->assertSessionHas('app_alert_type', 'error');
        $this->assertNotNull($en->fresh());

        $this->actingAs($admin)->from(route('admin.languages.index'))->delete(route('admin.languages.destroy', $lang))
            ->assertRedirect(route('admin.languages.index'))->assertSessionHas('app_alert_type', 'success');
        $this->assertNull(Language::find($lang->id));
        $this->assertDirectoryDoesNotExist(base_path('lang/'.self::LANG));
    }

    // ── Rewrite (WI-08d): tightened inputs ─────────────────────────────────

    public function test_rewrite_refuses_unsafe_or_unknown_values(): void
    {
        $admin = $this->admin();
        $news = $this->category('News');

        // Blog: only the two statuses the form offers, only existing categories; list cells escaped.
        $fields = ['title' => 'Post', 'description' => 'x', 'category' => [$news->id], 'image' => UploadedFile::fake()->image('c.jpg')];
        $this->actingAs($admin)->from(route('admin.blogs.create'))->post(route('admin.blogs.store'), ['status' => 'draft'] + $fields)
            ->assertSessionHas('app_alert_type', 'error');
        $this->actingAs($admin)->from(route('admin.blogs.create'))->post(route('admin.blogs.store'), ['status' => 'publish', 'category' => [999]] + $fields)
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertSame(0, Blog::count());
        $this->blogPost('<script>alert(1)</script>');
        $cell = $this->actingAs($admin)->dataTable(route('admin.blogs.index'))->json('data.0.1');
        $this->assertStringNotContainsString('<script>', $cell);

        // Pages: type is public (0) / private (1).
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.pages.store'), ['page_title' => 'Terms', 'page_content' => '<p>The terms</p>', 'type' => 'page', 'active' => 1]));
        $this->assertSame(0, Page::count());

        // Languages: the code is a folder name, so no path characters.
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.languages.store'), ['name' => 'X', 'code' => '../zq', 'direction' => 'ltr']));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.languages.store'), ['name' => 'X', 'code' => self::LANG, 'direction' => 'up']));
        $this->assertDirectoryDoesNotExist(base_path('lang/'.self::LANG));

        // Translate save only writes files that already exist in the language folder.
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.languages.store'), ['name' => 'Quenya', 'code' => self::LANG, 'direction' => 'ltr']));
        $lang = Language::where('code', self::LANG)->firstOrFail();
        $config = base_path('config/app.php');
        $before = md5_file($config);
        $this->actingAs($admin)->from(route('admin.languages.translates', self::LANG))->post(route('admin.languages.translates.update', $lang), [
            'translates' => ['pagination' => ['next' => 'N'], '../../config/app' => ['name' => 'pwned']],
        ])->assertSessionHas('app_alert_type', 'error');
        $this->assertSame($before, md5_file($config));
        $this->assertNotSame(['next' => 'N'], include base_path('lang/'.self::LANG.'/pagination.php'), 'nothing is written when one file is refused');
    }

    public function test_policies_allow_only_superadmins(): void
    {
        $admin = $this->admin();
        $user = $this->plainUser();

        foreach ([Blog::class, Page::class, Language::class] as $model) {
            foreach (['viewAny', 'create', 'deleteAny', 'reorder'] as $ability) {
                $this->assertTrue($admin->can($ability, $model), "$ability $model admin");
                $this->assertFalse($user->can($ability, $model), "$ability $model user");
            }
        }
    }
}
