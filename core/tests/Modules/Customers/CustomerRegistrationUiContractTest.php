<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use Tests\TestCase;

class CustomerRegistrationUiContractTest extends TestCase
{
    public function test_bundled_customer_registration_forms_submit_password_confirmation(): void
    {
        foreach (['crave', 'matcha', 'glow'] as $theme) {
            $view = file_get_contents(resource_path("views/store_templates/{$theme}/index.blade.php"));
            $script = file_get_contents(base_path("../assets/store_templates/{$theme}/js/script.js"));

            $this->assertStringContainsString('id="cvRegPassConfirm"', $view, $theme);
            $this->assertStringContainsString(
                'password_confirmation: $("#cvRegPassConfirm").value',
                $script,
                $theme
            );
        }
    }
}
