<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\OutputEncoding;

use Juwri\Core\Support\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** P6-73: the scheme guard behind every owner-typed public link. */
class SafeUrlLinkTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function dangerous(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'tab in scheme' => ["java\tscript:alert(1)"],
            'leading space' => [' javascript:alert(1)'],
            'newline in scheme' => ["java\nscript:alert(1)"],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'protocol relative' => ['//evil.example/x'],
            'backslash host' => ['\\\\evil.example'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_dangerous_links_are_rejected(string $url): void
    {
        $this->assertNull(SafeUrl::link($url));
    }

    /** @return array<string, array{0: string}> */
    public static function fine(): array
    {
        return [
            'https' => ['https://a.test/x?y=1'],
            'http' => ['http://a.test'],
            'mailto' => ['mailto:a@b.test'],
            'tel' => ['tel:+123456'],
            'whatsapp' => ['whatsapp://send?phone=1'],
            'path' => ['/shop?item=12'],
            'fragment' => ['#menu'],
        ];
    }

    #[DataProvider('fine')]
    public function test_ordinary_links_pass_unchanged(string $url): void
    {
        $this->assertSame($url, SafeUrl::link($url));
    }

    public function test_empty_is_null(): void
    {
        $this->assertNull(SafeUrl::link(null));
        $this->assertNull(SafeUrl::link('   '));
    }
}
