<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Tests\TestCase;

/**
 * The owner menu editor (views/owner/menu) exposes the allergen catalog: a picker
 * in the item form, the item's saved allergens in the edit payload + restore,
 * and allergen badges on the item card. Backend save (ManagesMenu) already
 * handled `allergies[]` — this guards the view wiring that was missing.
 */
class MenuAllergenEditorTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function test_menu_editor_has_allergen_picker(string $theme): void
    {
        $index = file_get_contents(resource_path("views/{$theme}/index.blade.php"));
        $this->assertStringContainsString('name="allergies[]"', $index, "$theme: allergen picker present");
        $this->assertStringContainsString('$allergies', $index, "$theme: iterates the allergen catalog");
        // restore in openEditItem
        $this->assertStringContainsString('[name="allergies[]"]', $index, "$theme: restores allergen checkboxes on edit");
        $this->assertStringContainsString('data.allergies', $index, "$theme: reads allergies from the edit payload");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function test_item_payload_and_card_expose_allergens(string $theme): void
    {
        $item = file_get_contents(resource_path("views/{$theme}/item.blade.php"));
        $this->assertStringContainsString("'allergies' => \$menu->allergies", $item, "$theme: edit payload includes allergies");
        $this->assertStringContainsString('$menu->allergies', $item, "$theme: card renders allergen badges");
    }

    public static function themes(): array
    {
        return ['owner' => ['owner/menu']];
    }
}
