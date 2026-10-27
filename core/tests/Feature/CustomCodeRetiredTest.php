<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P9-4: soft-retire the raw custom-code fields (head/footer/CSS/JS + raw
 * Pixel/Tag). They no longer render on the storefront, the owner editor no
 * longer exposes them, and the controller no longer persists them — GA/Pixel/
 * font go through the validated P5-1 structured integrations instead. The DB
 * columns stay dormant (no migration, no data deleted): reversible.
 */
class CustomCodeRetiredTest extends TestCase
{
    use RefreshDatabase;

    private function layout(): string
    {
        return file_get_contents(resource_path('views/store_templates/layout.blade.php'));
    }

    public function test_storefront_layout_has_no_raw_custom_code_sinks(): void
    {
        $layout = $this->layout();
        foreach ([
            '$store->head_code',
            '$store->js_code',
            '$store->footer_code',
            'sanitize_css($store->css_code)',
            '$store->facebook_pixel',
            '$store->google_tag',
        ] as $sink) {
            $this->assertStringNotContainsString($sink, $layout, "raw sink still rendered: $sink");
        }

        // GA/Pixel/font still render — through the validated structured partial.
        $this->assertStringContainsString(
            "@include('store_templates.partials.structured-integrations')",
            $layout,
            'structured integrations (P5-1) must still render'
        );
    }

    public function test_owner_controller_does_not_persist_raw_custom_code(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/User/StoreController.php'));
        foreach (['head_code', 'js_code', 'footer_code', 'css_code', 'facebook_pixel', 'google_tag'] as $field) {
            $this->assertStringNotContainsString(
                '$request->'.$field,
                $controller,
                "controller still persists raw field: $field"
            );
        }
    }

    public function test_owner_editor_no_longer_exposes_raw_code_fields(): void
    {
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        foreach (['head_code', 'js_code', 'footer_code', 'css_code'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $edit, "editor still exposes: $field");
        }
    }

    public function test_existing_stored_code_is_not_deleted_columns_stay_dormant(): void
    {
        // A restaurant that already had code (custom_code_enabled) keeps it in the
        // column — soft-retire never wipes data; it just stops rendering/persisting.
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'custom_code_enabled' => true, 'js_code' => 'console.log(1)',
        ]);
        $store->refresh();

        $this->assertSame('console.log(1)', $store->js_code, 'existing stored code is preserved (dormant, no data loss)');
    }
}
