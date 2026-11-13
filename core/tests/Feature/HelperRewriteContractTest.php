<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WI-13a: pins helpers of Helper.php whose output is a stored or
 * printed format (the XOR tokens on QR codes) or is shown to people (UA
 * labels, counts, interval names, theme paths). Vectors were produced by the
 * previous implementation.
 */
class HelperRewriteContractTest extends TestCase
{
    public function test_xor_tokens_match_the_previous_implementation(): void
    {
        $this->assertSame('d/TLKBlTaA==', app_xor_encrypt('menu-42', 'pw'));
        $this->assertSame('6ws/gNc=', app_xor_encrypt('12345'));
        $long = str_repeat('ab', 60); // > 100 bytes: 8-byte keystream steps
        $this->assertSame(
            'x01DR94S3s5fzHCXvc8M4AYGiynQkwSru/L0S6z42apPzrOjskLU5nx96Iulc88B01mnZQSLlWvoQvZmSi/o9BTj445A0pM45wJR6difHJCp/KcvvAAV8mxxj2F/2tVV06MkvD7xkFYV1xEYgJ+Gi8WghgSc52Ym',
            app_xor_encrypt($long, 'key'),
        );
        $this->assertSame('menu-42', app_xor_decrypt('d/TLKBlTaA==', 'pw'));
        $this->assertSame($long, app_xor_decrypt(app_xor_encrypt($long, 'key'), 'key'));
        $this->assertSame('', app_xor_decrypt(['x'], 'pw'));
    }

    public function test_user_agent_labels(): void
    {
        $cases = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' => ['iPhone', 'Handheld Browser'],
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => ['Android', 'Chrome'],
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0' => ['Windows 10', 'Chrome'],
            'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0 Safari/537.36 Edge/15.15063' => ['Windows 7', 'Edge'],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' => ['Mac OS X', 'Safari'],
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0' => ['Ubuntu', 'Firefox'],
            'Opera/9.80 (Windows NT 5.1; U) Presto/2.9 Version/12.00' => ['Windows XP', 'Opera'],
            'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.2)' => ['Windows Server 2003/XP x64', 'Internet Explorer'],
            'curl/8.0' => ['Other', 'Other'],
        ];
        foreach ($cases as $ua => [$system, $browser]) {
            $_SERVER['HTTP_USER_AGENT'] = $ua;
            $this->assertSame($system, user_os_info(), $ua);
            $this->assertSame($browser, user_browser_info(), $ua);
        }

        $_SERVER['HTTP_USER_AGENT'] = 'curl/8.0';
        $this->assertSame('Other', user_os_info());
        $this->assertSame('Other', user_browser_info());
    }

    public function test_small_formatting_helpers(): void
    {
        $this->assertSame('950', format_number_count(950));
        $this->assertSame('1.2K', format_number_count(1234));
        $this->assertSame('3.5M', format_number_count(3456789));
        $this->assertSame('-', plan_interval_text('WEEKLY'));
        $this->assertSame(___('Yearly'), plan_interval_text('YEARLY'));
        $this->assertSame([], validate_recaptcha());
        $this->assertSame('Hello Ann', ___('Hello :name', ['name' => 'Ann']));
    }

    public function test_theme_paths_follow_the_session_choice(): void
    {
        config(['app.theme' => 'juwri']);
        $this->assertSame('templates.juwri.', active_theme());
        $this->assertSame('assets/templates/juwri/', active_theme(true));

        session(['template' => 'other']);
        $this->assertSame('other', active_theme_name());
        $this->assertSame('templates.other.', active_theme());

        session(['template' => '  ']);
        $this->assertSame('juwri', active_theme_name());
    }

    public function test_quick_switch_prints_hidden_zero_and_checkbox_one(): void
    {
        ob_start();
        $returned = app_switch('Enable', 'enable_x', true, 'Hint');
        $html = ob_get_clean();

        $this->assertSame('', $returned);
        $this->assertStringContainsString('<input type="hidden" name="enable_x" value="0">', $html);
        $this->assertStringContainsString('name="enable_x" id="enable_x" value="1" checked>', $html);
        $this->assertStringContainsString('<label class="form-check-label fw-medium" for="enable_x">Enable</label>', $html);
        $this->assertStringContainsString('<small class="text-body d-block mt-1">Hint</small>', $html);
    }
}
