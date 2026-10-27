<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Support\Html;
use PHPUnit\Framework\TestCase;

/**
 * cleanPage() keeps two curated widgets that operators cannot otherwise express
 * safely: a contact FORM (action forced server-side, restricted input types) and an
 * <iframe> EMBED (host allow-listed, force-sandboxed). These invariants are the XSS
 * boundary for those widgets and must not regress.
 */
class HtmlCleanPageWidgetsTest extends TestCase
{
    public function test_form_is_kept_but_action_and_method_are_stripped(): void
    {
        $out = Html::cleanPage('<form action="https://evil.com/x" method="get" onsubmit="go()"><input name="a"></form>');

        $this->assertStringContainsString('data-mb-form', $out);          // marked for render-time wiring
        $this->assertStringNotContainsString('evil.com', $out);           // operator action gone
        $this->assertStringNotContainsString('method=', $out);
        $this->assertStringNotContainsString('onsubmit', $out);
        $this->assertStringContainsString('name="a"', $out);
    }

    public function test_credential_and_file_inputs_are_dropped(): void
    {
        $out = Html::cleanPage('<form><input type="password" name="pw"><input type="file" name="cv"><input type="email" name="em"></form>');

        $this->assertStringNotContainsString('name="pw"', $out);
        $this->assertStringNotContainsString('name="cv"', $out);
        $this->assertStringContainsString('type="email"', $out);
    }

    public function test_unknown_input_type_is_coerced_to_text(): void
    {
        $out = Html::cleanPage('<form><input type="whatever" name="x"></form>');

        $this->assertStringContainsString('name="x"', $out);
        $this->assertStringNotContainsString('type="whatever"', $out);
        $this->assertStringContainsString('type="text"', $out);
    }

    public function test_button_formaction_stripped_and_type_forced(): void
    {
        $out = Html::cleanPage('<form><button formaction="https://evil.com">x</button><button>y</button></form>');

        $this->assertStringNotContainsString('formaction', $out);
        // The bare button (no type) is forced to type="button" so it can't submit.
        $this->assertStringContainsString('type="button"', $out);
    }

    public function test_allowlisted_iframe_is_kept_and_sandboxed(): void
    {
        $out = Html::cleanPage('<iframe src="https://www.youtube.com/embed/abc" onload="hack()" width="560"></iframe>');

        $this->assertStringContainsString('youtube.com/embed/abc', $out);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"', $out);
        $this->assertStringContainsString('loading="lazy"', $out);
        $this->assertStringNotContainsString('hack()', $out);
    }

    public function test_disallowed_and_dangerous_iframes_are_dropped(): void
    {
        $out = Html::cleanPage(
            '<iframe src="https://evil.com/x"></iframe>'
            .'<iframe src="javascript:alert(1)"></iframe>'
            .'<iframe src="http://www.youtube.com/embed/x"></iframe>'   // http (not https) → dropped
        );

        $this->assertStringNotContainsString('evil.com', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('http://www.youtube.com', $out);
    }

    public function test_subdomain_of_allowlisted_host_is_accepted(): void
    {
        $out = Html::cleanPage('<iframe src="https://music.youtube.com/embed/x"></iframe>');
        $this->assertStringContainsString('music.youtube.com/embed/x', $out);
    }
}
