<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Tests\TestCase;

/**
 * ISS-B-004 — user_os_info() / user_browser_info() must not 500 when the request
 * carries no User-Agent header ($_SERVER['HTTP_USER_AGENT'] absent). Browsers
 * always send a UA, but UA-less API/monitoring clients reach these via
 * user_ip_info() in RegisterController@register. Pre-fix: "Undefined array key
 * HTTP_USER_AGENT" → 500.
 */
class UserAgentHelperNoUaTest extends TestCase
{
    public function test_user_os_info_tolerates_missing_user_agent(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $this->assertSame('Other', user_os_info());
    }

    public function test_user_browser_info_tolerates_missing_user_agent(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        $this->assertSame('Other', user_browser_info());
    }

    public function test_user_os_info_still_classifies_a_real_user_agent(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
        $this->assertSame('Windows 10', user_os_info());
        unset($_SERVER['HTTP_USER_AGENT']);
    }
}
