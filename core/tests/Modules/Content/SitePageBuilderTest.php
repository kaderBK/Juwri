<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Models\Site;
use App\Models\SitePage;
use Juwri\Modules\Content\Models\SitePageRevision;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Services\SitePages\LegacyHomeBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * HOME-PAGES HP-40/42/43/45: builder backend — admin-gated routes, untrusted
 * JSON re-validated on save, publish snapshot + pruned revisions + restore,
 * draft preview with noindex, unpublish kill switch, home-page delete
 * protection, upload validation.
 */
class SitePageBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Admin chrome touches many optional settings keys — use the null-returning
        // double (AdminDashboardTest pattern) instead of the strict stdClass.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share(['admin_languages' => collect(), 'notifications' => collect(), 'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0]);
        config(['app.installed' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => 'admin', 'status' => 1]);
    }

    private function makeHome(): SitePage
    {
        $site = Site::create(['key' => 'b-'.uniqid(), 'name' => 'B', 'active' => true]);

        return app(LegacyHomeBridge::class)->seed($site)['page'];
    }

    public function test_routes_are_admin_gated(): void
    {
        $page = $this->makeHome();

        $this->get(route('admin.site-pages.index'))->assertRedirect();
        $this->get(route('admin.site-pages.edit', $page->id))->assertRedirect();

        $user = User::factory()->create(['user_type' => 'user', 'status' => 1]);
        $this->actingAs($user)->get(route('admin.site-pages.index'))->assertStatus(302);
    }

    public function test_index_and_edit_render_for_admin(): void
    {
        app()->setLocale('en'); // asserts English UI copy that is now ___()-translated
        $page = $this->makeHome();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.site-pages.index'))->assertOk()->assertSeeHtml('composer');
        $this->actingAs($admin)->get(route('admin.site-pages.edit', $page->id))->assertOk()->assertSeeHtml('sitePageComposer');
    }

    public function test_save_revalidates_untrusted_json(): void
    {
        $page = $this->makeHome();

        $res = $this->actingAs($this->admin())->putJson(route('admin.site-pages.save', $page->id), [
            'blocks' => ['blocks' => [
                ['type' => 'hero', 'content' => ['headline' => ['en' => str_repeat('x', 999)], 'evil' => 'y'], 'settings' => ['hack' => '1']],
                ['type' => 'not_a_block'],
                ['type' => 'richtext', 'content' => ['body' => ['en' => '<p>ok</p><script>bad()</script>']]],
            ]],
        ]);

        $res->assertOk();
        $blocks = $page->fresh()->blocks['blocks'];
        $this->assertCount(2, $blocks);
        $this->assertSame(160, mb_strlen($blocks[0]['content']['headline']['en']), 'maxlen clamped');
        $this->assertArrayNotHasKey('evil', $blocks[0]['content']);
        $this->assertSame([], $blocks[0]['settings']);
        $this->assertStringNotContainsString('<script>', $blocks[1]['content']['body']['en']);
    }

    public function test_publish_snapshots_writes_revision_and_prunes(): void
    {
        $page = $this->makeHome();
        $admin = $this->admin();

        // Publish 23 times → only 20 revisions kept.
        for ($i = 0; $i < 23; $i++) {
            $this->actingAs($admin)->postJson(route('admin.site-pages.publish', $page->id), ['label' => "v$i"])->assertOk();
        }

        $page->refresh();
        $this->assertSame('published', $page->status);
        $this->assertNotNull($page->published_blocks);
        $this->assertSame(20, SitePageRevision::where('site_page_id', $page->id)->count());
        // Newest kept, oldest pruned.
        $labels = SitePageRevision::where('site_page_id', $page->id)->orderByDesc('id')->pluck('label');
        $this->assertSame('v22', $labels->first());
        $this->assertFalse($labels->contains('v0'));
    }

    public function test_unpublish_is_kill_switch_and_restore_updates_draft_only(): void
    {
        $page = $this->makeHome();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.site-pages.publish', $page->id), ['label' => 'first'])->assertOk();
        $revision = SitePageRevision::where('site_page_id', $page->id)->firstOrFail();

        // Mutate draft, then restore the revision → draft matches snapshot, publish untouched.
        $this->actingAs($admin)->putJson(route('admin.site-pages.save', $page->id), ['blocks' => ['blocks' => []]])->assertOk();
        $this->assertSame([], $page->fresh()->blocks['blocks']);

        $this->actingAs($admin)->postJson(route('admin.site-pages.restore', [$page->id, $revision->id]))->assertOk();
        $this->assertCount(count(LegacyHomeBridge::LEGACY_ORDER), $page->fresh()->blocks['blocks']);

        // Unpublish → legacy home renders again on '/'.
        $this->actingAs($admin)->postJson(route('admin.site-pages.publish', $page->id), ['unpublish' => true])->assertOk();
        $this->assertSame('draft', $page->fresh()->status);
    }

    public function test_preview_renders_draft_with_noindex(): void
    {
        $page = $this->makeHome();

        $res = $this->actingAs($this->admin())->get(route('admin.site-pages.preview', $page->id));

        $res->assertOk();
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $res->assertSeeHtml('data-site-block="hero"'); // bridge draft renders the kit hero block
    }

    public function test_home_page_cannot_be_deleted(): void
    {
        $page = $this->makeHome();

        $this->actingAs($this->admin())->delete(route('admin.site-pages.destroy', $page->id))->assertForbidden();
        $this->assertNotNull($page->fresh());
    }

    public function test_slug_page_crud_with_reserved_slug_fence(): void
    {
        $this->makeHome(); // ensures site exists
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.site-pages.store'), ['title' => 'About', 'slug' => 'pricing'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($admin)->post(route('admin.site-pages.store'), ['title' => 'About', 'slug' => 'about-us'])
            ->assertRedirect();
        $created = SitePage::where('slug', 'about-us')->firstOrFail();
        $this->assertSame('draft', $created->status);

        $this->actingAs($admin)->delete(route('admin.site-pages.destroy', $created->id))->assertRedirect();
        $this->assertNull(SitePage::find($created->id));
    }

    public function test_upload_rejects_non_images(): void
    {
        $this->makeHome();

        $res = $this->actingAs($this->admin())->postJson(route('admin.site-pages.upload'), [
            'image' => UploadedFile::fake()->create('evil.php', 10, 'text/php'),
        ]);

        $res->assertStatus(422);
    }
}
