<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\TemplateRollout;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\TemplateMigration;
use Juwri\Modules\Storefront\Models\ThemeTemplateMeta;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Superadmin menu-template management (post_template_meta overlay) and the
 * fallback rule: restaurants whose template was deleted render the
 * admin-configured fallback; disabled templates are hidden from the picker
 * but keep rendering for restaurants already using them.
 */
class PostTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        // The admin layout normally gets these from ViewServiceProvider composers,
        // which only register when APP_INSTALLED — share them directly for tests.
        view()->share([
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function makeOwnerAndRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5,
                'scan_limit' => 999,
                'menu_limit' => 999,
                'allow_ordering' => 1,
                'table_qr' => 0,
                'allow_pro' => '0',
                'allow_advanced' => '0',
                'allow_on_free' => '0',
                'allow_on_try' => '0',
                'allow_on_main' => '0',
                'allow_viewonly' => '0',
                'allow_custom' => '0',
                'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);

        return [$owner, $store];
    }

    private function setTemplate(Store $store, string $template): void
    {
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
    }

    /** Deleted/unknown template renders the configured fallback on the public menu */
    public function test_missing_template_falls_back_on_public_view(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'ghost-theme');

        config(['settings.fallback_store_theme' => 'aurora']);

        $response = $this->get(route('publicView', $store->slug));
        $response->assertOk();
        $response->assertSeeHtml('aurora');
    }

    /** Unconfigured fallback resolves to the shipped default */
    public function test_fallback_defaults_when_unconfigured(): void
    {
        config(['settings.fallback_store_theme' => null]);
        $service = app(StorefrontThemeService::class);

        $this->assertSame('crave', $service->resolve('ghost-theme'));
        $this->assertSame('aurora', $service->resolve('aurora'));
    }

    /**
     * WI-03: the legacy base-product templates were removed. A store still saved
     * on one renders the fallback; a saved `flipbook` store (image menus) renders
     * on folio, its first-party successor that reads the same image_menus data.
     */
    public function test_removed_legacy_templates_fall_back(): void
    {
        config(['settings.fallback_store_theme' => null]);
        $service = app(StorefrontThemeService::class);

        foreach (['lala-theme', 'old-2-theme', 'classic-theme', 'modern-theme', 'Ice-cream-theme'] as $removed) {
            $this->assertFalse($service->exists($removed), "$removed is gone");
            $this->assertSame('crave', $service->resolve($removed), "$removed falls back to the default");
        }
        $this->assertFalse($service->exists('flipbook'));
        $this->assertSame('folio', $service->resolve('flipbook'));
        $this->assertSame('shelf', $service->resolve('lala-theme', 'retail'), 'vertical fallback still applies');

        config(['settings.fallback_store_theme' => 'aurora']);
        $this->assertSame('aurora', $service->resolve('lala-theme'), 'admin-configured fallback wins');
        $this->assertSame('folio', $service->resolve('flipbook'), 'flipbook keeps its image-menu successor');
    }

    /** WI-03: a store saved on a removed template renders the fallback storefront. */
    public function test_store_on_removed_template_renders_the_fallback(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'old-2-theme');
        config(['settings.fallback_store_theme' => null]);

        $this->get(route('publicView', $store->slug))->assertOk()->assertSeeHtml('store_templates/crave/')->assertDontSeeHtml('store_templates/old-2-theme');
    }

    /** A configured-but-deleted fallback is itself guarded */
    public function test_deleted_fallback_is_guarded(): void
    {
        config(['settings.fallback_store_theme' => 'also-deleted']);
        $service = app(StorefrontThemeService::class);

        $this->assertSame('crave', $service->resolve('ghost-theme'));
    }

    /** Disabling hides from the picker but grandfathered restaurants keep rendering */
    public function test_disabled_template_hidden_from_picker_but_still_renders(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'aurora');

        ThemeTemplateMeta::create(['slug' => 'aurora', 'enabled' => false]);

        $service = app(StorefrontThemeService::class);
        $this->assertArrayNotHasKey('aurora', $service->available());
        $this->assertArrayHasKey('aurora', $service->all());

        $this->get(route('publicView', $store->slug))->assertOk();
    }

    /** Admin index page renders with discovered templates */
    public function test_admin_index_renders(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get(route('admin.menu-templates.index'))->assertOk()->assertSeeHtml('aurora');
    }

    /** Admin can override display name / featured / enabled via the edit form */
    public function test_admin_can_update_template_meta(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.menu-templates.update', 'aurora'), [
                'display_name' => 'Golden Aurora',
                'description' => 'Custom description',
                'featured' => 1,
                'enabled' => 1,
                'sort_order' => 5,
            ])
            ->assertRedirect(route('admin.menu-templates.index'));

        $this->assertDatabaseHas('theme_template_meta', [
            'slug' => 'aurora',
            'display_name' => 'Golden Aurora',
            'enabled' => 1,
            'sort_order' => 5,
        ]);

        $all = app(StorefrontThemeService::class)->all();
        $this->assertSame('Golden Aurora', $all['aurora']['name']);
    }

    /** Toggle flips enabled state, creating the meta row on first use */
    public function test_admin_toggle_flips_enabled(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.toggle', 'aurora'));
        $this->assertDatabaseHas('theme_template_meta', ['slug' => 'aurora', 'enabled' => 0]);

        $this->actingAs($admin)->post(route('admin.menu-templates.toggle', 'aurora'));
        $this->assertDatabaseHas('theme_template_meta', ['slug' => 'aurora', 'enabled' => 1]);
    }

    /** Fallback setting only accepts enabled, existing templates */
    public function test_fallback_setting_validated(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.fallback'), [
            'fallback_store_theme' => 'ghost-theme',
        ]);
        $this->assertNotEquals('ghost-theme', PlatformSettings::get('fallback_store_theme', false));

        $this->actingAs($admin)->post(route('admin.menu-templates.fallback'), [
            'fallback_store_theme' => 'aurora',
        ]);
        $this->assertSame('aurora', PlatformSettings::get('fallback_store_theme', false));
    }

    /** Bulk reassign moves every restaurant off a (possibly deleted) template */
    public function test_admin_reassign_moves_restaurants(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'ghost-theme');
        $this->setTemplate($r2, 'ghost-theme');

        $this->actingAs($this->makeAdmin())->post(route('admin.menu-templates.reassign'), [
            'from' => 'ghost-theme',
            'to' => 'aurora',
        ])->assertRedirect(route('admin.menu-templates.index'));

        $this->assertSame('aurora', store_options($r1->id, 'store_template'));
        $this->assertSame('aurora', store_options($r2->id, 'store_template'));
    }

    /** Reassign rejects a disabled target template */
    public function test_reassign_rejects_disabled_target(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'ghost-theme');

        ThemeTemplateMeta::create(['slug' => 'aurora', 'enabled' => false]);

        $this->actingAs($this->makeAdmin())->post(route('admin.menu-templates.reassign'), [
            'from' => 'ghost-theme',
            'to' => 'aurora',
        ]);

        $this->assertSame('ghost-theme', store_options($r1->id, 'store_template'));
    }

    /** Restaurants drill-down lists users of a template, including deleted ones */
    public function test_restaurants_page_lists_users(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'ghost-theme');

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.menu-templates.restaurants', 'ghost-theme'))->assertOk()->assertSeeHtml($store->title);
    }

    /** Index warns about disabled templates that restaurants still use */
    public function test_index_warns_about_disabled_templates_in_use(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'aurora');
        ThemeTemplateMeta::create(['slug' => 'aurora', 'enabled' => false]);

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.menu-templates.index'))->assertOk()->assertSeeHtml(___('Disabled templates still in use'));

        // No warning when the disabled template has no users
        StoreOption::updateStoreOption($store->id, 'store_template', 'velvet');
        $this->actingAs($this->makeAdmin())
            ->get(route('admin.menu-templates.index'))->assertOk()->assertDontSeeHtml('Disabled templates still in use');
    }

    /** Bulk action disables/features several templates at once */
    public function test_bulk_action_updates_selected_templates(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.bulk'), [
            'bulk_action' => 'disable',
            'slugs' => ['aurora', 'velvet'],
        ])->assertRedirect();

        $this->assertDatabaseHas('theme_template_meta', ['slug' => 'aurora', 'enabled' => 0]);
        $this->assertDatabaseHas('theme_template_meta', ['slug' => 'velvet', 'enabled' => 0]);

        $this->actingAs($admin)->post(route('admin.menu-templates.bulk'), [
            'bulk_action' => 'feature',
            'slugs' => ['velvet'],
        ]);
        $this->assertDatabaseHas('theme_template_meta', ['slug' => 'velvet', 'featured' => 1]);
    }

    /** Bulk action rejects unknown slugs and actions */
    public function test_bulk_action_validates_input(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.bulk'), [
            'bulk_action' => 'disable',
            'slugs' => ['ghost-theme'],
        ]);
        $this->assertDatabaseMissing('theme_template_meta', ['slug' => 'ghost-theme']);

        $this->actingAs($admin)->post(route('admin.menu-templates.bulk'), [
            'bulk_action' => 'delete-everything',
            'slugs' => ['aurora'],
        ]);
        $this->assertDatabaseMissing('theme_template_meta', ['slug' => 'aurora']);
    }

    /** Bulk disable cannot remove the last enabled template */
    public function test_bulk_disable_keeps_at_least_one_enabled(): void
    {
        $admin = $this->makeAdmin();
        $service = app(StorefrontThemeService::class);
        $allSlugs = array_keys($service->discover());

        $this->actingAs($admin)->post(route('admin.menu-templates.bulk'), [
            'bulk_action' => 'disable',
            'slugs' => $allSlugs,
        ]);

        $this->assertNotEmpty(app(StorefrontThemeService::class)->available());
    }

    /** Non-admins are redirected away */
    public function test_non_admin_blocked(): void
    {
        [$owner] = $this->makeOwnerAndRestaurant();

        $this->actingAs($owner)
            ->get(route('admin.menu-templates.index'))
            ->assertRedirect(route('login'));
    }

    /**
     * I2: sort nudges a template up/down, rewrites the whole sequence to
     * sort_order = (i+1)*10, edges are a no-op, and it never re-enables a
     * disabled template (a fresh firstOrNew row would default enabled=true).
     */
    public function test_admin_sort_reorders_and_preserves_enabled(): void
    {
        $admin = $this->makeAdmin();
        $order = array_keys(app(StorefrontThemeService::class)->all());
        $this->assertGreaterThan(2, count($order));
        $second = $order[1];

        // Nudge the 2nd template up — it becomes first, whole sequence stamped.
        $this->actingAs($admin)
            ->post(route('admin.menu-templates.sort', $second), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame($second, array_keys(app(StorefrontThemeService::class)->all())[0]);
        $this->assertDatabaseHas('theme_template_meta', ['slug' => $second, 'sort_order' => 10]);

        // Moving the first template up again is a graceful edge no-op.
        $this->actingAs($admin)
            ->post(route('admin.menu-templates.sort', $second), ['direction' => 'up'])
            ->assertRedirect();
        $this->assertSame($second, array_keys(app(StorefrontThemeService::class)->all())[0]);

        // Sorting a disabled template must not silently re-enable it.
        ThemeTemplateMeta::updateOrCreate(['slug' => 'velvet'], ['enabled' => false]);
        $this->actingAs($admin)
            ->post(route('admin.menu-templates.sort', 'velvet'), ['direction' => 'down']);
        $this->assertDatabaseHas('theme_template_meta', ['slug' => 'velvet', 'enabled' => 0]);
    }

    /**
     * I5: the default template (new-signup seed) accepts only enabled templates,
     * persists, and defaultSlug() honours it — but a configured-but-disabled
     * default (or none) resolves to the first available template, never empty.
     */
    public function test_admin_default_template_set_and_precedence(): void
    {
        $admin = $this->makeAdmin();

        // Rejects a disabled/unknown target.
        $this->actingAs($admin)->post(route('admin.menu-templates.default'), [
            'default_post_template' => 'ghost-theme',
        ]);
        $this->assertNotSame('ghost-theme', PlatformSettings::get('default_post_template', false));

        // Accepts an enabled template and persists it.
        $this->actingAs($admin)->post(route('admin.menu-templates.default'), [
            'default_post_template' => 'aurora',
        ]);
        $this->assertSame('aurora', PlatformSettings::get('default_post_template', false));

        // Precedence (config drives the getter, set it directly as the app boot would):
        config(['settings.default_post_template' => 'aurora']);
        $this->assertSame('aurora', app(StorefrontThemeService::class)->defaultSlug());

        // A configured-but-disabled default drops to the first available template.
        ThemeTemplateMeta::updateOrCreate(['slug' => 'aurora'], ['enabled' => false]);
        $svc = app(StorefrontThemeService::class);
        $this->assertNotSame('aurora', $svc->defaultSlug());
        $this->assertArrayHasKey($svc->defaultSlug(), $svc->available());

        // No configured default also resolves to a real, available template.
        config(['settings.default_post_template' => null]);
        $svc2 = app(StorefrontThemeService::class);
        $this->assertArrayHasKey($svc2->defaultSlug(), $svc2->available());
    }

    /**
     * I7: a partial reassign (explicit post_ids) moves ONLY the checked
     * restaurants — the whereIn constraint must not sweep the rest — and lands
     * back on the same template's restaurants page.
     */
    public function test_partial_reassign_moves_only_selected(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $this->setTemplate($r2, 'aurora');

        $this->actingAs($this->makeAdmin())->post(route('admin.menu-templates.reassign'), [
            'from' => 'aurora',
            'to' => 'velvet',
            'post_ids' => [$r1->id],
        ])->assertRedirect(route('admin.menu-templates.restaurants', 'aurora'));

        $this->assertSame('velvet', store_options($r1->id, 'store_template'));
        $this->assertSame('aurora', store_options($r2->id, 'store_template'));
    }

    /** I7: the restaurants drill-down filters by ?q across title / slug / owner email. */
    public function test_restaurants_search_filters_by_query(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $this->setTemplate($r2, 'aurora');

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.menu-templates.restaurants', ['slug' => 'aurora', 'q' => $r1->title]))->assertOk()->assertSeeHtml($r1->title)->assertDontSeeHtml($r2->title);
    }

    /**
     * B1: the ?preview_template override is render-only and admin-gated — a guest
     * and a logged-in non-admin both keep seeing the store's real template, while
     * an admin sees the chosen one. Guards against visitors probing hidden themes.
     */
    public function test_admin_preview_override_gated_to_admins(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($store, 'aurora');

        $url = route('publicView', $store->slug).'?preview_template=velvet';

        // Guest — no override; the store renders its real template (aurora).
        $this->get($url)->assertOk()->assertDontSeeHtml('store_templates/velvet');

        // Logged-in non-admin (the owner) — likewise denied the override.
        $this->actingAs($owner)->get($url)->assertOk()->assertDontSeeHtml('store_templates/velvet');

        // Admin — gets the render-only override.
        $this->actingAs($this->makeAdmin())->get($url)->assertOk()->assertSeeHtml('store_templates/velvet');
    }

    /** Store-first switcher lists stores with their current theme and filters by ?q. */
    public function test_stores_page_lists_and_searches(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $this->setTemplate($r2, 'velvet');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('admin.menu-templates.stores'))->assertOk()->assertSeeHtml($r1->title)->assertSeeHtml($r2->title);

        $this->actingAs($admin)->get(route('admin.menu-templates.stores', ['q' => $r1->title]))->assertOk()->assertSeeHtml($r1->title)->assertDontSeeHtml($r2->title);
    }

    /** Switching one store changes only its template, and rejects a disabled target. */
    public function test_switch_store_changes_template_and_validates(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $admin = $this->makeAdmin();

        // Disabled/unknown target rejected.
        $this->actingAs($admin)->post(route('admin.menu-templates.stores.switch'), [
            'store_id' => $r1->id, 'to' => 'ghost-theme',
        ]);
        $this->assertSame('aurora', store_options($r1->id, 'store_template'));

        // Valid switch, lands back on the stores page.
        $this->actingAs($admin)->post(route('admin.menu-templates.stores.switch'), [
            'store_id' => $r1->id, 'to' => 'velvet',
        ])->assertRedirect(route('admin.menu-templates.stores'));
        $this->assertSame('velvet', store_options($r1->id, 'store_template'));
    }

    /** Cross-template migration moves ONLY the selected templates' stores. */
    public function test_bulk_reassign_moves_only_selected_templates(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        [, $r3] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $this->setTemplate($r2, 'velvet');
        $this->setTemplate($r3, 'lala-theme'); // not selected — must stay

        $this->actingAs($this->makeAdmin())->post(route('admin.menu-templates.bulk-reassign'), [
            'slugs' => ['aurora', 'velvet'],
            'to' => 'crumb',
        ])->assertRedirect(route('admin.menu-templates.index'));

        $this->assertSame('crumb', store_options($r1->id, 'store_template'));
        $this->assertSame('crumb', store_options($r2->id, 'store_template'));
        $this->assertSame('lala-theme', store_options($r3->id, 'store_template'));
    }

    /** Bulk migration drops the target from its own source set and rejects a disabled target. */
    public function test_bulk_reassign_validates(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'crumb');
        $admin = $this->makeAdmin();

        // Only source == target → nothing to move, store unchanged.
        $this->actingAs($admin)->post(route('admin.menu-templates.bulk-reassign'), [
            'slugs' => ['crumb'], 'to' => 'crumb',
        ]);
        $this->assertSame('crumb', store_options($r1->id, 'store_template'));

        // Disabled target rejected.
        ThemeTemplateMeta::create(['slug' => 'velvet', 'enabled' => false]);
        $this->actingAs($admin)->post(route('admin.menu-templates.bulk-reassign'), [
            'slugs' => ['crumb'], 'to' => 'velvet',
        ]);
        $this->assertSame('crumb', store_options($r1->id, 'store_template'));
    }

    /** The index exposes the new migration controls; the restaurants page the preview-target control. */
    public function test_migration_controls_render(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('admin.menu-templates.index'))->assertOk()->assertSeeHtml('bulkReassignForm')->assertSeeHtml('js-card-migrate')->assertSeeHtml(route('admin.menu-templates.stores'));

        $this->actingAs($admin)->get(route('admin.menu-templates.restaurants', 'aurora'))->assertOk()->assertSeeHtml('js-row-preview')->assertSeeHtml('data-preview-url');
    }

    /** Every migration is recorded and can be reverted, restoring each store's prior theme. */
    public function test_migration_recorded_and_undoable(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $this->setTemplate($r2, 'aurora');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.reassign'), [
            'from' => 'aurora', 'to' => 'velvet',
        ]);
        $this->assertSame('velvet', store_options($r1->id, 'store_template'));

        $mig = TemplateMigration::latest('id')->first();
        $this->assertNotNull($mig);
        $this->assertSame('velvet', $mig->to_template);
        $this->assertSame(2, $mig->count);

        $this->actingAs($admin)->post(route('admin.menu-templates.migrations.undo', $mig->id));

        $this->assertSame('aurora', store_options($r1->id, 'store_template'));
        $this->assertSame('aurora', store_options($r2->id, 'store_template'));
        $this->assertNotNull($mig->fresh()->undone_at);
    }

    /** Undo leaves alone any store re-themed after the batch (no clobber). */
    public function test_undo_skips_stores_rethemed_after_the_batch(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $this->setTemplate($r2, 'aurora');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.reassign'), ['from' => 'aurora', 'to' => 'velvet']);
        $mig = TemplateMigration::latest('id')->first();

        // Admin re-themes r1 after the batch — undo must not touch it.
        $this->setTemplate($r1, 'crumb');

        $this->actingAs($admin)->post(route('admin.menu-templates.migrations.undo', $mig->id));

        $this->assertSame('crumb', store_options($r1->id, 'store_template'));
        $this->assertSame('aurora', store_options($r2->id, 'store_template'));
    }

    /** An already-reverted migration is inert on a second undo. */
    public function test_undo_is_idempotent(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.stores.switch'), ['store_id' => $r1->id, 'to' => 'velvet']);
        $mig = TemplateMigration::latest('id')->first();

        $this->actingAs($admin)->post(route('admin.menu-templates.migrations.undo', $mig->id));
        $this->assertSame('aurora', store_options($r1->id, 'store_template'));

        // Move it again by hand, then re-fire the same (already-undone) migration: no-op.
        $this->setTemplate($r1, 'velvet');
        $this->actingAs($admin)->post(route('admin.menu-templates.migrations.undo', $mig->id));
        $this->assertSame('velvet', store_options($r1->id, 'store_template'));
    }

    /** Segment assign moves only the matching stores and records an undoable migration. */
    public function test_segment_assign_moves_matching_stores(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        [, $r2] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');
        $this->setTemplate($r2, 'velvet');
        \DB::table('stores')->where('id', $r1->id)->update(['is_verified' => 1]);
        \DB::table('stores')->where('id', $r2->id)->update(['is_verified' => 0]);
        $admin = $this->makeAdmin();

        // Preview page renders for a filter.
        $this->actingAs($admin)->get(route('admin.menu-templates.segments', ['verified' => '1']))->assertOk()->assertSeeHtml($r1->title);

        // Assign verified stores → crumb.
        $this->actingAs($admin)->post(route('admin.menu-templates.segments.assign'), [
            'verified' => '1', 'to' => 'crumb',
        ])->assertRedirect(route('admin.menu-templates.segments'));

        $this->assertSame('crumb', store_options($r1->id, 'store_template'));
        $this->assertSame('velvet', store_options($r2->id, 'store_template'));

        $mig = TemplateMigration::latest('id')->first();
        $this->assertSame('segment', $mig->kind);
        $this->assertSame('crumb', $mig->to_template);
    }

    /** Segment assign refuses to run with no filter (never blanket-assigns the platform). */
    public function test_segment_assign_requires_a_filter(): void
    {
        [, $r1] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r1, 'aurora');

        $this->actingAs($this->makeAdmin())->post(route('admin.menu-templates.segments.assign'), [
            'to' => 'crumb',
        ]);

        $this->assertSame('aurora', store_options($r1->id, 'store_template'));
    }

    /** A rollout splits the segment into arms, applies the variant theme, records each arm. */
    public function test_rollout_splits_segment_into_arms(): void
    {
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            [, $r] = $this->makeOwnerAndRestaurant();
            $this->setTemplate($r, 'aurora');
            \DB::table('stores')->where('id', $r->id)->update(['is_verified' => 1]);
            $ids[] = $r->id;
        }
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.rollouts.start'), [
            'verified' => '1', 'to' => 'crumb', 'percentage' => 50,
        ])->assertRedirect(route('admin.menu-templates.rollouts'));

        $rollout = TemplateRollout::latest('id')->first();
        $this->assertNotNull($rollout);
        $this->assertSame('crumb', $rollout->target_template);
        $this->assertSame(count($ids), $rollout->stores()->count());

        // Each store's live theme matches its arm.
        foreach ($rollout->stores as $rs) {
            $expected = $rs->arm === 'variant' ? 'crumb' : 'aurora';
            $this->assertSame($expected, store_options($rs->store_id, 'store_template'));
        }

        // If any store went variant, the theme change is recorded as an undoable migration.
        if ($rollout->stores()->where('arm', 'variant')->exists()) {
            $this->assertSame('rollout', TemplateMigration::latest('id')->first()->kind);
        }
    }

    /** Rollout start rejects a missing filter or an out-of-range split. */
    public function test_rollout_validates_filter_and_percentage(): void
    {
        [, $r] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r, 'aurora');
        \DB::table('stores')->where('id', $r->id)->update(['is_verified' => 1]);
        $admin = $this->makeAdmin();
        $before = TemplateRollout::count();

        // No filter.
        $this->actingAs($admin)->post(route('admin.menu-templates.rollouts.start'), ['to' => 'crumb', 'percentage' => 50]);
        $this->assertSame($before, TemplateRollout::count());

        // Out-of-range percentage.
        $this->actingAs($admin)->post(route('admin.menu-templates.rollouts.start'), ['verified' => '1', 'to' => 'crumb', 'percentage' => 150]);
        $this->assertSame($before, TemplateRollout::count());
    }

    /** A running rollout can be stopped. */
    public function test_rollout_can_be_stopped(): void
    {
        [, $r] = $this->makeOwnerAndRestaurant();
        $this->setTemplate($r, 'aurora');
        \DB::table('stores')->where('id', $r->id)->update(['is_verified' => 1]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.menu-templates.rollouts.start'), ['verified' => '1', 'to' => 'crumb', 'percentage' => 50]);
        $rollout = TemplateRollout::latest('id')->first();
        $this->assertSame('running', $rollout->status);

        $this->actingAs($admin)->post(route('admin.menu-templates.rollouts.stop', $rollout->id));
        $this->assertSame('stopped', $rollout->fresh()->status);
        $this->assertNotNull($rollout->fresh()->stopped_at);
    }

    /** The rollouts page renders. */
    public function test_rollouts_page_renders(): void
    {
        $this->actingAs($this->makeAdmin())->get(route('admin.menu-templates.rollouts'))->assertOk()->assertSeeHtml(___('Start an A/B rollout'));
    }
}
