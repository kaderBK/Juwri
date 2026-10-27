<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Notification;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Content\Models\Adsense;
use Juwri\Modules\Content\Models\Faq;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * WI-10: superadmin screens rebuilt on the owner-ui kit.
 *
 * Every rebuilt screen renders the kit admin shell (admin.layout) without the
 * retired admin stack (assets/admin, assets/admin-nexlink), Bootstrap markup or
 * icon-font classes. The old admin.layouts.main bridge is gone.
 */
class AdminKitScreensTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    /** Route names of full pages rebuilt on the kit (grows per WI-10 slice). */
    private const PAGES = [
        'admin.dashboard',
        'admin.taxes.index', 'admin.allergies.index', 'admin.faqs.index', 'admin.testimonials.index',
        'admin.subscriber.index', 'admin.blogs.index', 'admin.blogs.create', 'admin.blog.category.index',
        'admin.blog.comments.index', 'admin.advertisements.index', 'admin.notifications.index',
        'admin.coupons.index', 'admin.mailtemplates.index', 'admin.plugins.index', 'admin.templates.index',
        'admin.pages.index', 'admin.languages.index', 'admin.languages.translates', 'admin.languages.missing',
        'admin.transactions.index', 'admin.receipts',
        'admin.plans.index', 'admin.planoption.index', 'admin.gateways.index', 'admin.paylink.onboardings',
        'admin.settings.index', 'admin.users.index',
    ];

    /** Route parameters / query for the pages above that need them. */
    private const URL_ARGS = [
        'admin.faqs.index' => [[], '?lang=en'],
        'admin.pages.index' => [[], '?lang=en'],
        'admin.languages.translates' => [['code' => 'en'], ''],
        'admin.languages.missing' => [['code' => 'en'], ''],
    ];

    /** Side-panel (create) routes rebuilt on the kit. */
    private const PANELS = [
        'admin.taxes.create', 'admin.allergies.create', 'admin.faqs.create', 'admin.testimonials.create',
        'admin.subscriber.create', 'admin.blog.category.create', 'admin.coupons.create', 'admin.plugins.create',
        'admin.pages.create', 'admin.languages.create', 'admin.plans.create', 'admin.planoption.create',
        'admin.users.create',
    ];

    /** Markers of the retired stack; none may appear in a rebuilt screen. */
    private const RETIRED = [
        'assets/admin/', 'assets/admin-nexlink/', 'data-admin="legacy"',
        'class="btn ', ' btn-primary', 'form-control', 'form-select', 'form-label', 'class="row', 'col-md-', 'col-lg-',
        'card-body', 'd-flex', 'fi-rr-', 'fi fi-', 'icon-feather', 'fa-', 'slidePanel-',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
    }

    public function test_rebuilt_pages_render_the_kit_shell_only(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $admin = $this->admin();
        // Fixed identity: the users list prints it, and a random faker name could contain a marker (e.g. "fa-").
        $admin->forceFill(['name' => 'Admin Person', 'username' => 'adminperson', 'email' => 'admin.person@example.test'])->save();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => 1, 'direction' => 'ltr', 'position' => 1]);
        Faq::create(['faq_title' => 'Q', 'faq_content' => 'A', 'active' => 1]);
        config(['settings.blog_enable' => 1]);
        Adsense::query()->create(['slug' => 'head_code', 'provider_name' => 'Head', 'status' => 1, 'code' => '']);
        Notification::query()->create(['title' => 'Hello', 'type' => 'new_user', 'status' => 0]);
        foreach (self::PAGES as $name) {
            [$params, $query] = self::URL_ARGS[$name] ?? [[], ''];
            $url = route($name, $params).$query;
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-admin="kit"', $html, $name);
            $this->assertStringContainsString('class="ui-side"', $html, $name);
            $this->assertStringContainsString('assets/owner-ui/admin.js', $html, $name);
            $this->assertRetiredFree($html, $name);
        }
    }

    public function test_rebuilt_panels_use_kit_markup(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $admin = $this->admin();
        config(['settings.blog_enable' => 1]);
        foreach (self::PANELS as $name) {
            $html = $this->actingAs($admin)->get(route($name), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
            $this->assertStringContainsString('data-ui-panel-save', $html, $name);
            $this->assertRetiredFree($html, $name);
        }
    }

    public function test_every_admin_view_is_on_the_kit_and_the_bridge_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/admin/layouts/main.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/includes/legacy-styles.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/includes/legacy-scripts.blade.php'));

        $files = new \AppendIterator; // resources/views/admin + the ee modules' Views/admin (P1-13)
        foreach (ViewRoots::sub('admin') as $dir) {
            $files->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)));
        }
        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $rel = ViewRoots::relative($file->getPathname());
            $src = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('admin.layouts.main', $src, $rel);
            // The sidebar keeps old icon-font keys as data (mapped to SVG by <x-ui.shell.nav-icon>).
            if (! str_ends_with($rel, 'admin/includes/sidebar.blade.php')) {
                $this->assertRetiredFree($src, $rel);
            }
        }
    }

    public function test_owner_shell_no_longer_loads_the_nexlink_or_retired_admin_stack(): void
    {
        foreach (['legacy-styles', 'legacy-scripts'] as $part) {
            $src = (string) file_get_contents(resource_path("views/components/ui/shell/{$part}.blade.php"));
            $this->assertStringNotContainsString("asset('assets/admin-nexlink/", $src, $part);
            $this->assertStringNotContainsString("asset('assets/admin/", $src, $part);
            $this->assertStringNotContainsString("'assets/admin-nexlink/", $src, $part);
        }
        $this->assertFileExists(base_path('../assets/vendor/apexcharts/apexcharts.min.js'));
        $this->assertFileExists(base_path('../assets/vendor/apexcharts/LICENSE'));
    }

    public function test_shell_carries_the_admin_navigation_and_account_menu(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();
        foreach (['admin.settings.index', 'admin.taxes.index', 'admin.plans.index', 'admin.users.index', 'admin.notifications.index'] as $route) {
            $this->assertStringContainsString(route($route), $html, $route);
        }
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('action="'.route('logout').'"', $html);
        $this->assertStringContainsString('data-admin-search', $html);
    }

    public function test_settings_general_has_the_support_whatsapp_field_and_no_developer_credit(): void
    {
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->getContent();
        $this->assertStringContainsString('name="support_whatsapp"', $html);
        $this->assertStringNotContainsString('developer_credit', $html);
        $this->assertStringContainsString('role="tablist"', $html);

        $this->postJson(route('admin.settings.update'), [
            'general_setting' => 1, 'site_title' => 'juwri', 'support_whatsapp' => '966500000000',
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame('966500000000', PlatformSettings::stored('support_whatsapp'));
    }

    public function test_user_edit_and_password_pages_are_on_the_kit(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $admin = $this->admin();
        $admin->forceFill(['name' => 'Admin Person', 'username' => 'adminperson', 'email' => 'admin.person@example.test'])->save();
        $user = $this->plainUser();
        $user->forceFill(['name' => 'Plain Person', 'username' => 'plainperson', 'email' => 'plain.person@example.test'])->save();
        foreach ([route('admin.users.edit', $user->id), route('admin.users.password', $user->id)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-admin="kit"', $html, $url);
            $this->assertStringContainsString('id="change-plan-panel"', $html, $url);
            $this->assertStringContainsString('data-tenant-health', $html, $url);
            $this->assertStringNotContainsString('Quick'.'QR', $html, $url);
            $this->assertRetiredFree($html, $url);
        }
    }

    private function assertRetiredFree(string $html, string $name): void
    {
        // Random switch ids (ui-sw-<8 random chars>) can contain a marker such as "fa-" by chance.
        $html = preg_replace('/ui-sw-[a-z0-9]+/i', 'ui-sw-x', $html);
        foreach (self::RETIRED as $marker) {
            $this->assertStringNotContainsString($marker, $html, "{$name} still carries [{$marker}]");
        }
    }
}
