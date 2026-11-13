<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Support\StatusPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6-114: STATUS_PAGE_URL is optional. Set + valid shows "System status" in the marketing footer; unset or invalid shows nothing.
 * The footer.status key exists in all 11 locales, aligned, translated and in the right script.
 */
class StatusPageLinkTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    public function test_url_is_null_when_unset_or_not_http(): void
    {
        foreach ([null, '', '  ', 'javascript:alert(1)', 'ftp://x.test', 'status.example.com', 'https://a b.test'] as $v) {
            config(['appinfo.status_page_url' => $v]);
            $this->assertNull(StatusPage::url(), var_export($v, true));
        }
        config(['appinfo.status_page_url' => ' https://status.example.com/ ']);
        $this->assertSame('https://status.example.com/', StatusPage::url());
    }

    public function test_footer_shows_link_only_when_set(): void
    {
        $render = fn () => view('marketing.partials.footer', ['settings' => [], 'mkReseller' => false])->render();

        config(['appinfo.status_page_url' => null]);
        $this->assertStringNotContainsString(__('site.footer.status'), $render());

        config(['appinfo.status_page_url' => 'https://status.example.com']);
        $html = $render();
        $this->assertStringContainsString('href="https://status.example.com"', $html);
        $this->assertStringContainsString('>'.__('site.footer.status').'</a>', $html);
    }

    public function test_status_key_is_aligned_translated_and_in_the_right_script(): void
    {
        $en = (require base_path('lang/en/site.php'))['footer.status'];
        $scripts = ['ar' => '/\p{Arabic}/u', 'ur' => '/\p{Arabic}/u', 'hi' => '/\p{Devanagari}/u', 'ru' => '/\p{Cyrillic}/u', 'zh' => '/\p{Han}/u'];

        foreach (self::LOCALES as $loc) {
            $keys = array_keys(require base_path("lang/{$loc}/site.php"));
            $this->assertContains('footer.status', $keys, "{$loc}: key missing");
            $val = (require base_path("lang/{$loc}/site.php"))['footer.status'];
            $this->assertNotSame('', trim($val), $loc);
            if ($loc !== 'en') {
                $this->assertNotSame($en, $val, "{$loc}: identical to English");
            }
            if (isset($scripts[$loc])) {
                $this->assertMatchesRegularExpression($scripts[$loc], $val, "{$loc}: wrong script");
            } else {
                $this->assertDoesNotMatchRegularExpression('/[\p{Arabic}\p{Devanagari}\p{Cyrillic}\p{Han}]/u', $val, "{$loc}: wrong script");
            }
        }
    }
}
