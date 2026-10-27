<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Pci;

use Juwri\Core\Pci\PaymentScriptInventory;
use Tests\Support\PaymentViewGraph;
use Tests\TestCase;

/**
 * PCI DSS 4.0 req. 6.4.3: every script a payment page can load is in the inventory (config/payment_scripts.php +
 * the edition package's list) with a business reason, and every third-party file is SRI-pinned or says why it
 * cannot be. Read from the Blade sources of each payment page and everything it includes (PaymentViewGraph) —
 * the rendering tests (PaymentPagesRenderTest) check the HTML a browser actually receives.
 */
class PaymentScriptInventoryTest extends TestCase
{
    public function test_the_inventory_is_complete_and_every_entry_is_justified(): void
    {
        $this->assertNotEmpty(PaymentScriptInventory::pages());
        $this->assertSame([], PaymentScriptInventory::problems());
    }

    public function test_every_payment_view_exists(): void
    {
        $missing = array_values(array_filter(PaymentScriptInventory::views(), fn (string $view) => ! view()->exists($view)));

        $this->assertSame([], $missing, 'payment page views listed in the inventory that do not exist');
    }

    public function test_every_script_a_payment_page_can_load_is_in_the_inventory(): void
    {
        $unlisted = [];
        foreach (PaymentScriptInventory::pages() as $page => $definition) {
            foreach ((array) $definition['views'] as $view) {
                foreach (PaymentViewGraph::scriptsOf((string) $view) as $src => $from) {
                    if (PaymentScriptInventory::match($src, (string) $page) === null && PaymentScriptInventory::excludedMatch($src, (string) $page) === null) {
                        $unlisted[] = "{$page}: {$src}  (".implode(', ', array_unique($from)).')';
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unlisted)), "scripts on payment pages that are not in the PCI inventory (add them to config/payment_scripts.php with a reason — docs/dev/pci-scripts.md):\n".implode("\n", array_unique($unlisted)));
    }

    public function test_every_sri_pinned_script_tag_carries_its_hash_in_the_source(): void
    {
        $wrong = [];
        foreach (PaymentScriptInventory::pages() as $page => $definition) {
            foreach ((array) $definition['views'] as $view) {
                foreach (PaymentViewGraph::viewsOf((string) $view) as $child) {
                    if (! view()->exists($child)) {
                        continue;
                    }
                    $source = (string) file_get_contents(view()->getFinder()->find($child));
                    preg_match_all('/<script\b[^>]*\ssrc\s*=\s*"((?:https:)?\/\/[^"]+)"[^>]*>/is', $source, $tags, PREG_SET_ORDER);
                    foreach ($tags as [$tag, $src]) {
                        $id = PaymentScriptInventory::match($src, (string) $page);
                        $integrity = $id !== null ? (string) (PaymentScriptInventory::scripts()[$id]['integrity'] ?? '') : '';
                        if ($integrity === '') {
                            continue;
                        }
                        if (! str_contains($tag, 'integrity="'.$integrity.'"') || ! preg_match('/crossorigin="anonymous"/', $tag)) {
                            $wrong[] = "{$child}: {$src} must carry integrity=\"{$integrity}\" crossorigin=\"anonymous\"";
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($wrong)));
    }

    /**
     * The page CSP allows inline <script> and <style> only with the response nonce: a tag without it in any view a
     * payment page can render would be refused by the browser (a broken page) — or, worse, tempt a policy relaxation.
     */
    public function test_every_inline_script_and_style_a_payment_page_can_render_carries_the_nonce(): void
    {
        $missing = [];
        foreach (PaymentScriptInventory::pages() as $page => $definition) {
            foreach ((array) $definition['views'] as $view) {
                foreach (PaymentViewGraph::viewsOf((string) $view) as $child) {
                    if (! view()->exists($child)) {
                        continue;
                    }
                    // Blade comments and @php blocks are not markup.
                    $source = (string) preg_replace(['/\{\{--.*?--\}\}/s', '/@php\b(?!\s*\().*?@endphp/s'], '', (string) file_get_contents(view()->getFinder()->find($child)));
                    preg_match_all('/<(script|style)\b([^>]*)>/i', $source, $tags, PREG_SET_ORDER);
                    foreach ($tags as [$tag, $name, $attributes]) {
                        if (preg_match('/\bsrc\s*=/i', $attributes) || preg_match('/\bnonce\s*=/i', $attributes)) {
                            continue;
                        }
                        if (strtolower($name) === 'script' && preg_match('/\btype\s*=\s*"(application\/(ld\+)?json|text\/template|text\/html|text\/x-template)"/i', $attributes)) {
                            continue;
                        }
                        $missing[] = "{$child}: {$tag}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "inline <script>/<style> without nonce=\"{{ csp_nonce() }}\" on a payment page:\n".implode("\n", array_unique($missing)));
    }

    public function test_a_script_that_is_not_in_the_inventory_is_a_violation(): void
    {
        $html = '<html><head><script src="https://evil.example/skim.js"></script></head><body></body></html>';

        $this->assertSame(['unlisted script https://evil.example/skim.js on gateway.paystack'], PaymentScriptInventory::violations($html, 'gateway.paystack'));
        // A listed script on the wrong page is unlisted there.
        $paystack = '<script src="https://js.paystack.co/v1/inline.js"></script>';
        $this->assertSame([], PaymentScriptInventory::violations($paystack, 'gateway.paystack'));
        $this->assertSame(['unlisted script https://js.paystack.co/v1/inline.js on gateway.razorpay'], PaymentScriptInventory::violations($paystack, 'gateway.razorpay'));
    }

    public function test_a_removed_inventory_entry_makes_its_script_fail(): void
    {
        $scripts = (array) config('payment_scripts.scripts');
        unset($scripts['paystack-inline']);
        config(['payment_scripts.scripts' => $scripts]);

        $this->assertNotSame([], PaymentScriptInventory::violations('<script src="//js.paystack.co/v1/inline.js"></script>', 'gateway.paystack'));
    }

    public function test_sri_is_checked_on_pinned_third_party_scripts(): void
    {
        config(['payment_scripts.scripts.fixed-lib' => ['src' => 'https://cdn.example/lib-1.0.0.min.js', 'pages' => ['gateway.paystack'],
            'reason' => 'test', 'integrity' => 'sha384-AAAA']]);

        $ok = '<script src="https://cdn.example/lib-1.0.0.min.js" integrity="sha384-AAAA" crossorigin="anonymous"></script>';
        $this->assertSame([], PaymentScriptInventory::violations($ok, 'gateway.paystack'));

        $missing = PaymentScriptInventory::violations('<script src="https://cdn.example/lib-1.0.0.min.js"></script>', 'gateway.paystack');
        $this->assertCount(2, $missing);
        $this->assertStringContainsString('the inventory pins sha384-AAAA', $missing[0]);
        $this->assertStringContainsString('crossorigin', $missing[1]);

        $wrongHash = PaymentScriptInventory::violations('<script src="https://cdn.example/lib-1.0.0.min.js" integrity="sha384-BBBB" crossorigin="anonymous"></script>', 'gateway.paystack');
        $this->assertCount(1, $wrongHash);
    }

    public function test_a_third_party_entry_needs_sri_or_a_reason_why_not(): void
    {
        config(['payment_scripts.scripts.unpinned' => ['src' => 'https://cdn.example/x.js', 'pages' => ['*'], 'reason' => 'test']]);

        $this->assertContains('script unpinned: third-party script needs an SRI hash (integrity) or the reason it cannot have one (sri_exempt)', PaymentScriptInventory::problems());
    }

    public function test_inline_scripts_need_the_nonce_and_data_scripts_do_not(): void
    {
        $html = '<script>alert(1)</script><script nonce="abc">ok()</script><script type="application/json">{"a":1}</script>'
            .'<script type="application/ld+json">{}</script>';

        $violations = PaymentScriptInventory::violations($html, 'gateway.paystack');

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('inline script without the CSP nonce', $violations[0]);
        $this->assertStringContainsString('alert(1)', $violations[0]);
    }

    public function test_consent_gated_scripts_count_as_scripts(): void
    {
        $html = '<script type="text/plain" data-cookie-consent="analytics" data-src="https://tracker.example/t.js" nonce="n"></script>';

        $this->assertSame(['unlisted script https://tracker.example/t.js on gateway.paystack'], PaymentScriptInventory::violations($html, 'gateway.paystack'));
    }

    public function test_urls_are_normalised(): void
    {
        $this->assertSame('/assets/global/js/jquery.min.js', PaymentScriptInventory::normalize(url('/assets/global/js/jquery.min.js').'?v=123'));
        $this->assertSame('/assets/a.js', PaymentScriptInventory::normalize('/assets/a.js?v=1'));
        $this->assertSame('https://js.paystack.co/v1/inline.js', PaymentScriptInventory::normalize('//js.paystack.co/v1/inline.js'));
        $this->assertSame('https://www.googletagmanager.com/gtag/js?id=G-1', PaymentScriptInventory::normalize('https://www.googletagmanager.com/gtag/js?id=G-1'));
        $this->assertSame('http://plain.example/x.js', PaymentScriptInventory::normalize('http://plain.example/x.js'));
        $this->assertContains('plain-http script http://plain.example/x.js', PaymentScriptInventory::violations('<script src="http://plain.example/x.js"></script>', 'gateway.paystack'));
    }

    public function test_every_sri_exempt_script_has_a_written_reason_and_is_listed_in_the_doc(): void
    {
        $exempt = [];
        foreach ((array) config('payment_scripts.scripts') as $key => $entry) {
            if (array_key_exists('sri_exempt', $entry)) {
                $this->assertIsString($entry['sri_exempt'], "script $key: sri_exempt must be a sentence");
                $this->assertGreaterThanOrEqual(20, mb_strlen(trim($entry['sri_exempt'])), "script $key: sri_exempt needs a real reason");
                $this->assertArrayNotHasKey('integrity', $entry, "script $key: has both integrity and sri_exempt");
                $exempt[] = $key;
            }
        }
        $this->assertNotEmpty($exempt);
        $doc = (string) file_get_contents(base_path('../docs/dev/pci-scripts.md'));
        preg_match('/<!-- sri-exempt-list:start -->(.*)<!-- sri-exempt-list:end -->/s', $doc, $m);
        preg_match_all('/^\* `([a-z0-9-]+)`/m', $m[1] ?? '', $listed);
        sort($exempt);
        $documented = $listed[1];
        sort($documented);
        $this->assertSame($exempt, $documented, 'docs/dev/pci-scripts.md must list exactly the sri_exempt scripts');
    }
}
