<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

class StoreCreateUiContractTest extends TestCase
{
    public function test_wizard_validates_required_images_before_showing_ready_to_create(): void
    {
        $view = file_get_contents(
            resource_path('views/owner/stores/create.blade.php')
        );

        $this->assertStringContainsString('@click="review()"', $view);
        $this->assertStringContainsString('missing.reportValidity()', $view);
        $this->assertStringContainsString("this.step = 'confirm';", $view);
    }
}
