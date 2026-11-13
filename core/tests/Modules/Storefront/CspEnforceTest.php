<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Support\Csp\StorefrontPolicy;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * P9-2: enforce-readiness for the storefront CSP. The CSP_ENFORCE flag flip
 * (config/csp.php) is safe only when every legitimate inline <script>/<style>
 * in the store_templates carries a nonce — otherwise enforcing breaks them.
 * This ratchets that invariant so it can never regress, and pins the enforce
 * header shape. The env flip + the per-theme browser pass remain the owner's.
 */
class CspEnforceTest extends TestCase
{
    private const RETAINED_TEMPLATES = KeptTemplates::MODERN;

    /**
     * The enforce path keeps script execution strict. The scoped style-src-attr
     * compatibility exception is required by retained themes' dynamic styling.
     */
    public function test_enforce_policy_header_is_nonce_based(): void
    {
        $policy = new StorefrontPolicy; // not report-only => enforcing
        $response = new Response('');
        $policy->applyTo($response);

        $header = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($header, 'enforcing header is set');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $header);
        $this->assertStringContainsString("script-src-attr 'none'", $header);
        $this->assertStringContainsString("style-src 'self'", $header);
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $header);
        $this->assertStringContainsString("'nonce-", $header);
        $this->assertStringNotContainsString('unsafe-eval', $header);
    }

    /** Inline DOM event handlers are blocked by script-src-attr 'none'. */
    public function test_retained_templates_do_not_contain_inline_event_handlers(): void
    {
        $offenders = [];
        $files = [
            resource_path('views/store_templates/layout.blade.php'),
            resource_path('views/store_templates/_partials/splash.blade.php'),
            resource_path('views/store_templates/includes/item-carousel-assets.blade.php'),
            resource_path('views/store_templates/partials/branch-switcher.blade.php'),
        ];

        foreach (self::RETAINED_TEMPLATES as $template) {
            $dir = resource_path('views/store_templates/'.$template);

            foreach (File::allFiles($dir) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        foreach ($files as $file) {
            $body = File::get($file);

            if (preg_match_all('/\son(?:click|change|error|load|focus|blur|input|submit)\s*=/i', $body, $matches)) {
                $offenders[] = str_replace(resource_path('views/store_templates/').'/', '', $file).' ('.count($matches[0]).')';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "inline event handlers would be blocked under CSP_ENFORCE:\n".implode("\n", $offenders)
        );
    }

    /** Runtime-generated markup must obey the same script-attribute policy. */
    public function test_retained_template_scripts_do_not_generate_inline_event_handlers(): void
    {
        $offenders = [];

        foreach (self::RETAINED_TEMPLATES as $template) {
            $file = public_path('assets/store_templates/'.$template.'/js/script.js');

            if (! File::exists($file)) {
                $offenders[] = $template.' (missing script.js)';

                continue;
            }

            if (preg_match_all('/\son(?:click|change|error|load|focus|blur|input|submit)=/i', File::get($file), $matches)) {
                $offenders[] = $template.'/js/script.js ('.count($matches[0]).')';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "runtime markup contains CSP-blocked event attributes:\n".implode("\n", $offenders)
        );
    }

    /**
     * Ratchet: no inline <script>/<style> in store_templates may lack a nonce.
     * (Pre-P9-2 there were 151 such tags — enforcing would have broken them.)
     */
    public function test_no_un_nonced_inline_script_or_style_in_store_templates(): void
    {
        $dir = resource_path('views/store_templates');
        $offenders = [];

        foreach (File::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $body = $file->getContents();
            $rel = str_replace($dir.'/', '', $file->getPathname());

            // inline <script> = a <script ...> opening tag with no src= and no nonce
            if (preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce\b)[ >]/i', $body, $m)) {
                $offenders[] = $rel.' ('.count($m[0]).' <script>)';
            }
            // inline <style> = a <style ...> opening tag with no nonce
            if (preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $body, $m)) {
                $offenders[] = $rel.' ('.count($m[0]).' <style>)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "un-nonced inline blocks would break under CSP_ENFORCE:\n".implode("\n", $offenders)
        );
    }
}
