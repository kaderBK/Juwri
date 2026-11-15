<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu\Admin;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogComment;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * WI-08 characterisation: plan coupons (Billing), blog comment moderation,
 * e-mail templates and the plugin screen (Community).
 * Written against the pre-rewrite controllers; the rewrite must keep it green.
 */
class SiteExtrasAdminContractTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
        config(['settings.blog_enable' => 1]);
    }

    // ── Plan coupons ───────────────────────────────────────────────────────

    private function coupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'SAVE10', 'percentage' => 10, 'limit' => 5, 'used' => 0,
            'expiry_at' => now()->addMonth(), 'coupon_type' => 'percentage',
        ], $overrides));
    }

    public function test_coupons_admin_only(): void
    {
        $c = $this->coupon();
        $this->assertAdminOnly('get', route('admin.coupons.index'));
        $this->assertAdminOnly('post', route('admin.coupons.store'), ['code' => 'NEWONE', 'discount_percentage' => 5, 'expiry_at' => now()->addDay()->toDateTimeString(), 'uses_limit' => 1]);
        $this->assertAdminOnly('put', route('admin.coupons.update', $c), ['expiry_at' => now()->addDay()->toDateTimeString(), 'uses_limit' => 99, 'coupon_type' => 'fixed']);
        $this->assertAdminOnly('post', route('admin.coupons.delete'), ['ids' => [$c->id]]);
        $this->assertSame(1, Coupon::count());
        $this->assertSame(5, (int) $c->fresh()->limit);
    }

    public function test_coupons_list_create_update_delete(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.coupons.index'))->assertOk()->assertViewIs('admin.billing.coupons.index');
        $this->actingAs($admin)->get(route('admin.coupons.create'))->assertOk()->assertViewIs('admin.billing.coupons.create');

        $future = now()->addMonth()->format('Y-m-d\TH:i');
        $base = ['discount_percentage' => 15, 'expiry_at' => $future, 'uses_limit' => 10];
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.coupons.store'), ['code' => 'ab'] + $base));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.coupons.store'), ['code' => 'BAD-CODE'] + $base));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.coupons.store'), ['code' => 'ZERO', 'discount_percentage' => 0] + $base));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.coupons.store'), ['code' => 'PAST', 'expiry_at' => now()->subDay()->format('Y-m-d\TH:i')] + $base));
        $this->assertSame(0, Coupon::count());

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.coupons.store'), ['code' => 'WELCOME15', 'applies_interval' => 'yearly', 'first_period_only' => 1] + $base));
        $welcome = Coupon::where('code', 'WELCOME15')->firstOrFail();
        $this->assertSame(['percentage', 'yearly', 10], [$welcome->coupon_type, $welcome->applies_interval, (int) $welcome->limit]);
        $this->assertEquals(15, $welcome->percentage);
        $this->assertTrue((bool) $welcome->first_period_only);

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.coupons.store'), ['code' => 'ODD', 'applies_interval' => 'weekly'] + $base));
        $this->assertSame('any', Coupon::where('code', 'ODD')->value('applies_interval'));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.coupons.store'), ['code' => 'ODD'] + $base));

        $expired = $this->coupon(['code' => 'OLD', 'expiry_at' => now()->subDay()]);
        $rows = $this->actingAs($admin)->dataTable(route('admin.coupons.index'), 1, 'asc')->assertOk()
            ->assertJson(['recordsTotal' => 3])->json('data');
        $this->assertStringContainsString('ODD', $rows[0][1]);
        $this->assertStringContainsString('OLD', $rows[1][1]);
        $this->assertStringContainsString('data-tone="danger"', $rows[1][5]);
        $this->assertStringContainsString('15%', $rows[2][2]);
        $this->assertStringContainsString('percentage', $rows[2][6]);
        $this->assertStringContainsString(route('admin.coupons.edit', $welcome->id), $rows[2][7]);
        $this->assertStringContainsString('quick-check', $rows[2][8]);
        $this->assertCount(1, $this->actingAs($admin)->dataTable(route('admin.coupons.index'), 1, 'asc', 'WELC')->json('data'));

        $this->actingAs($admin)->get(route('admin.coupons.edit', $welcome))->assertOk()->assertViewHas('coupon');
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.coupons.update', $welcome), ['expiry_at' => $future, 'uses_limit' => 0, 'coupon_type' => 'fixed']));
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.coupons.update', $welcome), ['expiry_at' => now()->subDay()->format('Y-m-d\TH:i'), 'uses_limit' => 3, 'coupon_type' => 'fixed']));

        // Keeping the stored (even past) expiry is allowed; only a changed date must be in the future.
        $keep = $expired->expiry_at->format('Y-m-d H:i:s');
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.coupons.update', $expired), ['expiry_at' => $keep, 'uses_limit' => 7, 'coupon_type' => 'percentage']));
        $this->assertSame(7, (int) $expired->fresh()->limit);

        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.coupons.update', $welcome), [
            'expiry_at' => now()->addYear()->format('Y-m-d\TH:i'), 'uses_limit' => 3, 'coupon_type' => 'fixed', 'applies_interval' => 'monthly',
        ]));
        $welcome->refresh();
        $this->assertSame(['fixed', 'monthly', 3, 'WELCOME15'], [$welcome->coupon_type, $welcome->applies_interval, (int) $welcome->limit, $welcome->code]);
        $this->assertFalse((bool) $welcome->first_period_only);
        $this->assertEquals(15, $welcome->percentage, 'the discount itself is not editable');

        $this->actingAs($admin)->get(route('admin.coupons.show', $welcome))->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.coupons.destroy', $welcome))->assertNotFound();

        $this->assertPanelOk($this->actingAs($admin)->post(route('admin.coupons.delete'), ['ids' => [$welcome->id, $expired->id]]));
        $this->assertSame(['ODD'], Coupon::pluck('code')->all());
    }

    // ── Blog comments ──────────────────────────────────────────────────────

    private function blog(string $title = 'Hello'): Blog
    {
        return Blog::create(['author' => $this->admin()->id, 'title' => $title, 'slug' => str($title)->slug().'-'.uniqid(), 'description' => 'x', 'status' => 'publish']);
    }

    public function test_blog_comments_admin_only(): void
    {
        $c = BlogComment::create(['blog_id' => $this->blog()->id, 'name' => 'G', 'email' => 'g@example.test', 'comment' => 'Hi', 'active' => '0']);
        $this->assertAdminOnly('get', route('admin.blog.comments.index'));
        $this->assertAdminOnly('post', route('admin.blog.comments.approve', $c->id));
        $this->assertAdminOnly('post', route('admin.blog.comments.delete'), ['ids' => [$c->id]]);
        $this->assertSame('0', (string) (int) $c->fresh()->active);
    }

    public function test_blog_comments_list_filter_approve_delete(): void
    {
        $admin = $this->admin();
        $one = $this->blog('First post');
        $two = $this->blog('Second post');
        $guest = BlogComment::create(['blog_id' => $one->id, 'name' => '<i>Guest</i>', 'email' => 'g@example.test', 'comment' => '<script>alert(1)</script>', 'active' => '0']);
        $member = BlogComment::create(['blog_id' => $two->id, 'user_id' => $admin->id, 'comment' => 'Nice read', 'active' => '1']);

        $this->actingAs($admin)->get(route('admin.blog.comments.index'))->assertOk()
            ->assertViewIs('admin.blog.comments.index')->assertViewHas('blog_id', '');
        $this->actingAs($admin)->get(route('admin.blog.comments.index', ['blog_id' => $one->id]))->assertOk()
            ->assertViewHas('blog_id', (string) $one->id);

        $rows = $this->actingAs($admin)->dataTable(route('admin.blog.comments.index'), 2, 'asc')->assertOk()
            ->assertJson(['recordsTotal' => 2])->json('data');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('&lt;script&gt;', $rows[0][2]);
        $this->assertStringNotContainsString('<script>', json_encode($rows) ?: '');
        $this->assertStringContainsString('&lt;i&gt;Guest&lt;/i&gt;', $rows[0][0]);
        $this->assertStringContainsString(route('admin.blogs.edit', $one->id), $rows[0][1]);
        $this->assertStringContainsString(route('admin.blog.comments.approve', $guest->id), $rows[0][5]);
        $this->assertStringContainsString(route('admin.users.edit', $admin->id), $rows[1][0]);
        $this->assertStringNotContainsString('comments/'.$member->id.'/approve', $rows[1][5]);

        $filtered = $this->actingAs($admin)->dataTable(route('admin.blog.comments.index'), 2, 'asc', '', ['blog_id' => $two->id])->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame($member->id, $filtered[0]['DT_RowId']);
        $this->assertCount(1, $this->actingAs($admin)->dataTable(route('admin.blog.comments.index'), 2, 'asc', 'Nice')->json('data'));

        $this->actingAs($admin)->from(route('admin.blog.comments.index'))->post(route('admin.blog.comments.approve', $guest->id))
            ->assertRedirect(route('admin.blog.comments.index'));
        $this->assertSame('1', (string) (int) $guest->fresh()->active);

        $this->assertPanelOk($this->actingAs($admin)->post(route('admin.blog.comments.delete'), ['ids' => [$guest->id]]));
        $this->assertSame([$member->id], BlogComment::pluck('id')->all());
    }

    public function test_blog_comments_hidden_while_blog_is_off(): void
    {
        config(['settings.blog_enable' => 0]);
        $this->actingAs($this->admin())->get(route('admin.blog.comments.index'))->assertNotFound();
    }

    // ── E-mail templates ───────────────────────────────────────────────────

    public function test_mail_templates_admin_only(): void
    {
        $this->assertAdminOnly('get', route('admin.mailtemplates.index'));
        $this->assertAdminOnly('post', route('admin.mailtemplates.update'), ['email_sub_contact' => 'hacked']);
        $this->assertNotSame('hacked', PlatformSettings::stored('email_sub_contact'));
    }

    public function test_mail_templates_list_and_save(): void
    {
        $admin = $this->admin();
        $res = $this->actingAs($admin)->get(route('admin.mailtemplates.index'))->assertOk()->assertViewIs('admin.mailtemplates.index');
        $templates = collect($res->viewData('email_template'));
        $this->assertSame(
            ['new-order', 'signup-details', 'create-account', 'forgot-pass', 'contact_us', 'feedback', 'app-trial', 'merchant-campaign'],
            $templates->pluck('id')->all()
        );
        $contact = $templates->firstWhere('id', 'contact_us');
        $this->assertSame(['email_sub_contact', 'email_message_contact'], [$contact['subject'], $contact['message']]);
        $this->assertContains('{CONTACT_SUBJECT}', array_column($contact['shortcodes'], 'code'));
        $this->assertContains('{SITE_TITLE}', array_column($contact['shortcodes'], 'code'));
        $this->assertSame(['{SITE_TITLE}', '{SITE_URL}'], array_column($res->viewData('default_shortcodes'), 'code'));

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.mailtemplates.update'), [
            'email_setting' => '',
            'email_sub_contact' => 'New message: {CONTACT_SUBJECT}',
            'email_message_contact' => '<p>{MESSAGE}</p>',
        ]));
        $this->assertSame('New message: {CONTACT_SUBJECT}', PlatformSettings::stored('email_sub_contact'));
        $this->assertSame('<p>{MESSAGE}</p>', PlatformSettings::stored('email_message_contact'));
        $this->assertNull(PlatformSettings::stored('email_setting'));
    }

    // ── Plugins ────────────────────────────────────────────────────────────

    public function test_plugins_admin_only(): void
    {
        $this->assertAdminOnly('get', route('admin.plugins.index'));
        $this->assertAdminOnly('post', route('admin.plugins.enable', 'demo'));
    }

    public function test_plugins_screens_and_refusals(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.plugins.index'))->assertOk()->assertViewIs('admin.plugins.index')
            ->assertViewHas('plugins')->assertViewHas('installed_plugins')->assertViewHas('installed_plugins_not_in_marketplace');
        $this->actingAs($admin)->get(route('admin.plugins.create'))->assertOk()->assertViewIs('admin.plugins.create');

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.plugins.store'), []));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.plugins.store'), [
            'plugin_zip' => UploadedFile::fake()->create('x.txt', 1, 'text/plain'),
        ]));

        $missing = 'no-such-plugin-'.uniqid();
        foreach (['enable', 'disable'] as $action) {
            $this->actingAs($admin)->from(route('admin.plugins.index'))->post(route('admin.plugins.'.$action, $missing))
                ->assertRedirect(route('admin.plugins.index'));
        }
        $this->actingAs($admin)->from(route('admin.plugins.index'))->delete(route('admin.plugins.destroy', $missing))
            ->assertRedirect(route('admin.plugins.index'));

        foreach (['show', 'edit'] as $page) {
            $this->actingAs($admin)->get(route('admin.plugins.'.$page, $missing))->assertRedirect(route('admin.plugins.index'));
        }
        $this->actingAs($admin)->put(route('admin.plugins.update', $missing))->assertRedirect(route('admin.plugins.index'));
    }

    // ── Rewrite guarantees (WI-08) ─────────────────────────────────────────

    public function test_mail_template_save_only_writes_template_settings(): void
    {
        $before = PlatformSettings::stored('site_title');
        $this->assertPanelOk($this->actingAs($this->admin())->panelPost(route('admin.mailtemplates.update'), [
            'email_sub_feedback' => 'Feedback: {FEEDBACK_SUBJECT}',
            'site_title' => 'Taken over',
            'active_plugins' => ['evil'],
        ]));
        $this->assertSame('Feedback: {FEEDBACK_SUBJECT}', PlatformSettings::stored('email_sub_feedback'));
        $this->assertSame($before, PlatformSettings::stored('site_title'));
        $this->assertNotSame(json_encode(['evil']), PlatformSettings::stored('active_plugins'));
    }

    public function test_coupon_store_rejects_unknown_type_and_update_keeps_code_and_discount(): void
    {
        $admin = $this->admin();
        $future = now()->addMonth()->format('Y-m-d\TH:i');
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.coupons.store'), [
            'code' => 'WEIRD', 'coupon_type' => 'bogus', 'discount_percentage' => 5, 'expiry_at' => $future, 'uses_limit' => 1,
        ]));
        $c = $this->coupon();
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.coupons.update', $c), [
            'expiry_at' => $future, 'uses_limit' => 2, 'coupon_type' => 'free_delivery', 'code' => 'HACK', 'percentage' => 99,
        ]));
        $c->refresh();
        $this->assertSame(['SAVE10', 'free_delivery'], [$c->code, $c->coupon_type]);
        $this->assertEquals(10, $c->percentage);
    }

    public function test_plugin_actions_refuse_path_like_ids(): void
    {
        $admin = $this->admin();
        foreach (['..', 'a.b'] as $id) {
            $this->actingAs($admin)->from(route('admin.plugins.index'))->delete(route('admin.plugins.destroy', $id))
                ->assertRedirect(route('admin.plugins.index'));
        }
        $this->assertDirectoryExists(base_path('app'));
    }

    public function test_policies_and_panel_abilities_allow_only_superadmins(): void
    {
        $admin = $this->admin();
        $user = $this->plainUser();
        foreach ([Coupon::class, BlogComment::class] as $model) {
            foreach (['viewAny', 'create', 'deleteAny'] as $ability) {
                $this->assertTrue($admin->can($ability, $model));
                $this->assertFalse($user->can($ability, $model));
            }
        }
        foreach (['admin.plugins', 'admin.mail-templates', 'admin.site-template'] as $ability) {
            $this->assertTrue($admin->can($ability));
            $this->assertFalse($user->can($ability));
        }
    }

    public function test_plugin_install_enable_disable_remove_round_trip(): void
    {
        $admin = $this->admin();
        $id = 'wi08test'.getmypid();
        $zipPath = sys_get_temp_dir().'/'.$id.'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.json', json_encode(['id' => $id, 'name' => 'WI-08 test', 'version' => '1.0', 'min_app_version' => '0.1']));
        $zip->close();

        try {
            $upload = new UploadedFile($zipPath, $id.'.zip', 'application/zip', null, true);
            $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.plugins.store'), ['plugin_zip' => $upload]));
            $this->assertFileExists(base_path('plugins/'.$id.'/plugin.json'));

            $this->actingAs($admin)->from(route('admin.plugins.index'))->post(route('admin.plugins.enable', $id))->assertRedirect(route('admin.plugins.index'));
            $this->assertContains($id, (array) json_decode((string) PlatformSettings::stored('active_plugins'), true));
            config(['settings.active_plugins' => [$id]]);

            $this->actingAs($admin)->from(route('admin.plugins.index'))->delete(route('admin.plugins.destroy', $id));
            $this->assertFileExists(base_path('plugins/'.$id.'/plugin.json'), 'an enabled plugin is not removed');

            $this->actingAs($admin)->from(route('admin.plugins.index'))->post(route('admin.plugins.disable', $id))->assertRedirect(route('admin.plugins.index'));
            $this->assertNotContains($id, (array) json_decode((string) PlatformSettings::stored('active_plugins'), true));
            config(['settings.active_plugins' => []]);

            $this->actingAs($admin)->from(route('admin.plugins.index'))->delete(route('admin.plugins.destroy', $id))->assertRedirect(route('admin.plugins.index'));
            $this->assertDirectoryDoesNotExist(base_path('plugins/'.$id));
        } finally {
            @unlink($zipPath);
            File::deleteDirectory(base_path('plugins/'.$id));
        }
    }
}
