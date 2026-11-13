<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Support\TrustedHtml;
use Tests\TestCase;

/**
 * TrustedHtml renders admin-authored raw HTML AS-AUTHORED (no sanitising), only
 * stamping the request nonce onto inline <script>/<style> for CSP. The content of
 * a <script> must survive byte-for-byte (a DOMDocument round-trip would corrupt JS),
 * which is the whole reason this is a regex, not the cleanPage sanitiser.
 */
class TrustedHtmlTest extends TestCase
{
    public function test_script_is_kept_verbatim_and_nonce_stamped(): void
    {
        app()->instance('csp-nonce', 'TESTN');

        $out = TrustedHtml::render('<div>hi</div><script>let x = 1 < 2 && 3 > 2; foo("a&b");</script>');

        // Nonce added, but the JS body is untouched (incl. < > & which a DOM parse would mangle).
        $this->assertStringContainsString('<script nonce="TESTN">let x = 1 < 2 && 3 > 2; foo("a&b");</script>', $out);
        $this->assertStringContainsString('<div>hi</div>', $out);
    }

    public function test_inline_style_is_nonce_stamped(): void
    {
        app()->instance('csp-nonce', 'TESTN');

        $out = TrustedHtml::render('<style>.a{color:red}</style>');

        $this->assertStringContainsString('<style nonce="TESTN">.a{color:red}</style>', $out);
    }

    public function test_existing_nonce_is_not_doubled(): void
    {
        app()->instance('csp-nonce', 'TESTN');

        $out = TrustedHtml::render('<script nonce="already">x()</script>');

        $this->assertStringContainsString('<script nonce="already">x()</script>', $out);
        $this->assertStringNotContainsString('nonce="TESTN"', $out);
    }

    public function test_without_a_nonce_context_it_returns_raw(): void
    {
        app()->instance('csp-nonce', '');   // no nonce available

        $out = TrustedHtml::render('<script>x()</script>');

        $this->assertSame('<script>x()</script>', $out);
    }

    public function test_script_with_attributes_gets_nonce(): void
    {
        app()->instance('csp-nonce', 'TESTN');

        $out = TrustedHtml::render('<script src="https://cdn.example/x.js" defer></script>');

        $this->assertStringContainsString('nonce="TESTN"', $out);
        $this->assertStringContainsString('src="https://cdn.example/x.js"', $out);
    }
}
