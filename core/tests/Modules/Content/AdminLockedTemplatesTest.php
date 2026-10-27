<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\LockedTemplate;
use Juwri\Modules\Content\Models\LockedTemplatePermission;
use Juwri\Modules\Content\Models\LockedTemplateVersion;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Services\LockedTemplates\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Settings\PlatformSettings;
use Tests\Support\LockedTemplateFixtures;
use Tests\TestCase;

/**
 * Superadmin side: catalogue, preview, new versions (validated manifest), and who
 * may edit what (global defaults + per-user overrides). Admin-only.
 */
class AdminLockedTemplatesTest extends TestCase
{
    use LockedTemplateFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.installed' => true]);
        // The admin layout's shared view vars, exactly as production builds them.
        $this->shareLayoutGlobals(['site_admin_logo' => 'logo.png'], true);
    }

    private function admin(): User
    {
        return User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_index_and_preview_render_for_admin(): void
    {
        $tpl = $this->ltTemplate();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.locked-templates.index'))
            ->assertOk()->assertSee('Test template')->assertSee(___('Default editing permissions'));

        $this->actingAs($admin)->get(route('admin.locked-templates.preview', $tpl->id))->assertOk()->assertSeeHtml('<h1 data-lt-t="hero.t1">Welcome</h1>')->assertSeeHtml('noindex');
    }

    public function test_non_admin_is_kept_out(): void
    {
        $tpl = $this->ltTemplate();
        $user = User::factory()->create(['group_id' => '1', 'user_type' => 'user', 'status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('admin.locked-templates.index'))->assertRedirect();
        $this->actingAs($user)->post(route('admin.locked-templates.permissions.user'), [
            'user_id' => $user->id, 'mode' => 'custom', 'caps' => array_fill_keys(array_keys(Permissions::CAPS), 1), 'all_templates' => 1,
        ])->assertRedirect();
        $this->assertFalse(LockedTemplatePermission::query()->where('user_id', $user->id)->exists());
        $this->actingAs($user)->post(route('admin.locked-templates.status', $tpl->id), ['status' => 'draft'])->assertRedirect();
        $this->assertSame('published', $tpl->refresh()->status);
    }

    public function test_per_user_override_and_reset(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $tpl = $this->ltTemplate();

        $this->actingAs($admin)->post(route('admin.locked-templates.permissions.user'), [
            'user_id' => $target->id, 'mode' => 'custom', 'caps' => ['text' => 1], 'templates' => [$tpl->id],
        ])->assertRedirect();

        $caps = Permissions::forUser($target->id);
        $this->assertTrue($caps['text']);
        $this->assertFalse($caps['image']);
        $this->assertSame([$tpl->id], Permissions::allowedTemplates($target->id));

        $this->actingAs($admin)->post(route('admin.locked-templates.permissions.user'), ['user_id' => $target->id, 'mode' => 'default'])->assertRedirect();
        $this->assertFalse(Permissions::hasOverride($target->id));
        $this->assertSame(Permissions::defaults(), Permissions::forUser($target->id));
    }

    public function test_caps_added_later_fall_back_to_their_default(): void
    {
        // Stored before the 'icon' capability existed → it follows the built-in default.
        PlatformSettings::set(Permissions::OPTION_KEY, ['text' => true, 'image' => false]);
        $d = Permissions::defaults();
        $this->assertTrue($d['icon']);
        $this->assertFalse($d['image']);

        $user = User::factory()->create();
        LockedTemplatePermission::create(['user_id' => $user->id, 'caps' => ['text' => false]]);
        $this->assertTrue(Permissions::forUser($user->id)['icon']);
        $this->assertFalse(Permissions::forUser($user->id)['text']);
    }

    public function test_defaults_are_saved(): void
    {
        $this->actingAs($this->admin())->post(route('admin.locked-templates.defaults'), ['caps' => ['text' => 1, 'sections_toggle' => 1]])->assertRedirect();

        $d = Permissions::defaults();
        $this->assertTrue($d['text']);
        $this->assertTrue($d['sections_toggle']);
        $this->assertFalse($d['image']);
    }

    public function test_new_version_is_validated_and_created(): void
    {
        $admin = $this->admin();
        $tpl = $this->ltTemplate();

        $bad = $this->ltManifest();
        $bad['sections'][1]['html'] = '<a href="{{slot:hero.t1}}">x</a>';   // text slot feeding an href
        $this->actingAs($admin)->post(route('admin.locked-templates.versions.store', $tpl->id), ['from' => 1, 'manifest' => json_encode($bad)])
            ->assertSessionHasErrors('manifest');
        $this->assertSame(1, LockedTemplateVersion::query()->where('locked_template_id', $tpl->id)->count());

        $good = $this->ltManifest();
        $good['sections'][1]['slots'][0]['default'] = 'Welcome v2';
        $this->actingAs($admin)->post(route('admin.locked-templates.versions.store', $tpl->id), ['from' => 1, 'manifest' => json_encode($good), 'notes' => 'copy tweak'])
            ->assertRedirect(route('admin.locked-templates.index'));
        $v2 = $tpl->versionNumber(2);
        $this->assertNotNull($v2);
        $this->assertSame('Welcome v2', $v2->manifestData()['sections'][1]['slots'][0]['default']);
        $this->assertSame(1, (int) $tpl->refresh()->current_version);   // not auto-promoted

        $this->actingAs($admin)->post(route('admin.locked-templates.current', $tpl->id), ['version' => 2])->assertRedirect();
        $this->assertSame(2, (int) $tpl->refresh()->current_version);
    }

    public function test_import_command_validates_and_imports(): void
    {
        $dir = sys_get_temp_dir().'/lt-import-'.uniqid();
        mkdir($dir.'/good-tpl', 0777, true);
        mkdir($dir.'/bad-tpl', 0777, true);

        $good = $this->ltManifest();
        $good['meta'] = ['key' => 'good-tpl', 'version' => 1, 'name' => 'Good', 'assets_base' => 'assets/locked-templates/good-tpl/v1'];
        file_put_contents($dir.'/good-tpl/v1.json', json_encode($good));

        $bad = $this->ltManifest();
        $bad['meta'] = ['key' => 'bad-tpl', 'version' => 1, 'name' => 'Bad', 'assets_base' => 'assets/locked-templates/bad-tpl/v1'];
        $bad['sections'][0]['html'] .= '<script>var x="{{slot:header.t1}}"</script>';
        file_put_contents($dir.'/bad-tpl/v1.json', json_encode($bad));

        $this->artisan('locked-templates:import', ['path' => $dir, '--publish' => true])->assertExitCode(1);

        $this->assertTrue(LockedTemplate::query()->where('key', 'good-tpl')->where('status', 'published')->exists());
        $this->assertFalse(LockedTemplate::query()->where('key', 'bad-tpl')->exists());
    }
}
