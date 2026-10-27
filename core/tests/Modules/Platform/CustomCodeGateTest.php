<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P0-3: custom code (css/head/js/footer/pixels) is contained — never stored
 * unless a superadmin enabled it for the restaurant, never rendered raw, CSS
 * scrubbed. Default off.
 */
class CustomCodeGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeRestaurant(array $attrs = []): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234',
        ], $attrs));
    }

    public function test_disabled_post_never_persists_custom_code(): void
    {
        $store = $this->makeRestaurant([
            'custom_code_enabled' => false,
            'js_code' => '<script>steal()</script>',
            'css_code' => 'body{color:red}',
            'head_code' => '<meta>',
        ]);

        $store->refresh();
        $this->assertNull($store->js_code);
        $this->assertNull($store->css_code);
        $this->assertNull($store->head_code);
    }

    public function test_enabled_post_persists_custom_code(): void
    {
        $store = $this->makeRestaurant(['custom_code_enabled' => true, 'js_code' => 'console.log(1)']);
        $store->refresh();
        $this->assertSame('console.log(1)', $store->js_code);
    }

    public function test_sanitize_css_strips_dangerous_constructs(): void
    {
        $dirty = "@import url('//evil.test/x.css'); body{background:url(javascript:alert(1))} a{behavior:url(x)} </style><script>x</script> .e{width:expression(alert(1))}";
        $clean = sanitize_css($dirty);
        $this->assertStringNotContainsString('@import', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('behavior:', $clean);
        $this->assertStringNotContainsString('</style>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('expression(', $clean);
    }

    public function test_layout_render_sinks_are_retired(): void
    {
        // P9-4 superseded the P0-3 render-time gate: the raw custom-code sinks are
        // removed from the storefront layout entirely (no custom_code_enabled render
        // branch remains). Persistence gating (the model save-hook) is unchanged and
        // covered by the persist tests above. Full retirement: CustomCodeRetiredTest.
        $src = file_get_contents(resource_path('views/store_templates/layout.blade.php'));
        $this->assertSame(0, substr_count($src, 'custom_code_enabled'), 'no render-time gate left in layout');
        $this->assertStringNotContainsString('{!! $store->css_code !!}', $src);
        $this->assertStringNotContainsString('sanitize_css($store->css_code)', $src);
        $this->assertStringNotContainsString('{!! $store->js_code !!}', $src);
    }

    public function test_toggle_route_flips_flag_for_admin(): void
    {
        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
        $store = $this->makeRestaurant(['custom_code_enabled' => false]);

        $res = $this->actingAs($admin)->postJson(route('admin.stores.toggleCustomCode'), ['id' => $store->id]);
        $res->assertOk()->assertJson(['success' => true, 'enabled' => true]);
        $this->assertTrue((bool) $store->fresh()->custom_code_enabled);
    }

    public function test_toggle_route_blocked_for_non_admin(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $store = $this->makeRestaurant(['custom_code_enabled' => false]);

        $res = $this->actingAs($owner)->post(route('admin.stores.toggleCustomCode'), ['id' => $store->id]);
        $this->assertNotEquals(200, $res->getStatusCode());
        $this->assertFalse((bool) $store->fresh()->custom_code_enabled);
    }
}
