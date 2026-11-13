<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * Port of upstream 7360392a5 (admin flash toasts render escaped text).
 *
 * juwri's admin shows flashes through the kit toast, which writes textContent.
 * The flash reaches the page as hex-escaped JSON data, and admin.js turns a
 * server message into plain text in an inert document, so markup in exception
 * text or user-owned names never loads or runs.
 */
class AdminToastEscapingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
    }

    public function test_admin_flash_reaches_the_page_as_escaped_data(): void
    {
        session()->flash('app_alert_message', 'Import failed: <img src=x onerror=alert(1)></script><script>alert(2)</script>');
        session()->flash('app_alert_type', 'error');

        $html = view('admin.includes.scripts')->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertStringContainsString('ownerUi.toast', $html);
    }

    public function test_admin_js_parses_server_messages_in_an_inert_document(): void
    {
        $js = (string) file_get_contents(base_path('../assets/owner-ui/admin.js'));

        $this->assertMatchesRegularExpression('/function plain\(message\)\s*\{[^}]*DOMParser/s', $js);
        $this->assertStringNotContainsString('box.innerHTML', $js);
    }
}
