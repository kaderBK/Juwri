<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Juwri\Core\Editions\EditionPackages;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\ViewRoots;

/**
 * Phase 3 F1: no Blade view (resources/views and every installed edition package's view folders) loads a script from a
 * third-party host unless the tag carries Subresource Integrity, or the URL is one of the authorised third-party scripts
 * of the PCI DSS 6.4.3 inventory (config/payment_scripts.php `scripts` / `excluded`, plus each edition package's
 * `payment_scripts` manifest file), or it is listed in self::EXEMPT below with the reason SRI is impossible.
 *
 * Found by the Phase 3 reconcile: the POS customer display, floor and print-settings pages and the site-page editor
 * (plus the reseller panel's fallback) loaded Alpine from cdn.jsdelivr.net as an unpinned version range with no
 * integrity — a third party could change the code that runs on staff and admin pages. They now load the vendored
 * Alpine builds (VendoredAlpineTest).
 *
 * Static and file based (no app boot), so it also runs on the Community build.
 */
class ThirdPartyScriptIntegrityTest extends TestCase
{
    /**
     * Third-party scripts that are not on a payment page and cannot carry SRI (url glob => reason).
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        'https://challenges.cloudflare.com/turnstile/v0/api.js' => 'Cloudflare Turnstile (sign-up/contact abuse check): Cloudflare serves api.js as a living file and documents that it must not be pinned or proxied.',
        'https://js.stripe.com/v3/' => 'Stripe.js (wallet button partial): Stripe requires loading it from js.stripe.com (PCI) and updates it in place; no SRI possible.',
    ];

    /**
     * Used only while config/payment_scripts.php (the PCI script inventory, release gate) is not on this branch: the
     * same third-party payment and analytics scripts it authorises. Once the inventory is present it alone decides.
     *
     * @var array<string, string>
     */
    private const INTERIM_INVENTORY = [
        'https://checkout.razorpay.com/v1/checkout.js' => 'Razorpay Standard Checkout',
        'https://js.paystack.co/v1/inline.js' => 'Paystack Inline',
        'https://www.2checkout.com/checkout/api/2co.min.js' => '2Checkout 2co.js',
        'https://www.googletagmanager.com/gtag/js?id=*' => 'Google Analytics 4 tag (consent-gated)',
        'https://www.googletagmanager.com/gtm.js?id=*' => 'Google Tag Manager (consent-gated)',
        'https://connect.facebook.net/en_US/fbevents.js' => 'Meta pixel (consent-gated)',
        'https://embed.tawk.to/*' => 'Tawk.to live chat (admin opt-in)',
        'https://wati-integration-service.clare.ai/ShopifyWidget/shopifyWidget.js*' => 'WhatsApp chat bubble (admin opt-in)',
    ];

    public function test_no_view_loads_a_third_party_script_without_integrity_or_authorisation(): void
    {
        $allowed = self::allowedPatterns();
        $bad = [];
        foreach (self::viewFiles() as $file) {
            foreach (self::scan((string) file_get_contents($file)) as [$url, $hasIntegrity]) {
                if ($hasIntegrity || self::matchesAny($url, array_keys($allowed))) {
                    continue;
                }
                $bad[] = ViewRoots::relative($file).': '.$url;
            }
        }

        $this->assertSame([], $bad, "Third-party scripts without integrity and not authorised:\n".implode("\n", $bad)
            ."\nUse a vendored copy (public/assets/vendor) or add integrity=\"sha384-...\" crossorigin=\"anonymous\"; a payment-page script goes into the PCI script inventory.");
    }

    public function test_every_exemption_is_still_used(): void
    {
        $urls = [];
        foreach (self::viewFiles() as $file) {
            foreach (self::scan((string) file_get_contents($file)) as [$url]) {
                $urls[] = $url;
            }
        }
        $stale = [];
        foreach (array_keys(self::EXEMPT) as $pattern) {
            if (! array_filter($urls, fn (string $url) => self::matchesAny($url, [$pattern]))) {
                $stale[] = $pattern;
            }
        }

        $this->assertSame([], $stale, 'Exemptions no view uses any more — remove them.');
    }

    public function test_the_scanner_finds_the_cdn_alpine_tags_it_was_written_for(): void
    {
        $html = <<<'BLADE'
            <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
            <script nonce="{{ csp_nonce() }}"
                defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13/dist/cdn.min.js"></script>
            <script src="//cdn.example.com/lib.js"></script>
            <script src="https://cdnjs.cloudflare.com/x.js" integrity="sha384-abc" crossorigin="anonymous"></script>
            <script nonce="{{ csp_nonce() }}">var s = document.createElement('script'); s.src = 'https://evil.example/' + id;</script>
            {{-- <script src="https://commented.example/out.js"></script> --}}
            <script src="{{ asset('assets/vendor/alpinejs/alpine.min.js') }}"></script>
            BLADE;

        $this->assertSame([
            ['https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js', false],
            ['https://cdn.jsdelivr.net/npm/alpinejs@3.13/dist/cdn.min.js', false],
            ['https://cdn.example.com/lib.js', false],
            ['https://cdnjs.cloudflare.com/x.js', true],
            ['https://evil.example/', false],
        ], self::scan($html));
    }

    public function test_the_staff_and_admin_pages_load_the_vendored_alpine(): void
    {
        $views = dirname(__DIR__, 2).'/resources/views/';
        $expect = [
            'staff/pos/display.blade.php' => 'assets/pos/alpine.min.js',
            'staff/pos/floor.blade.php' => 'assets/pos/alpine.min.js',
            'staff/pos/settings.blade.php' => 'assets/pos/alpine.min.js',
            'admin/site-pages/edit.blade.php' => 'assets/vendor/alpinejs/alpine.min.js',
        ];
        foreach ($expect as $view => $asset) {
            $src = (string) file_get_contents($views.$view);
            $this->assertStringNotContainsString('alpinejs@', $src, $view);
            $this->assertMatchesRegularExpression(
                '#<script nonce="\{\{ csp_nonce\(\) \}\}"[^>]*\''.preg_quote($asset, '#').'\'#',
                $src,
                "{$view}: vendored Alpine with the CSP nonce"
            );
        }
    }

    /**
     * Third-party script URLs of one view: [url, tag has integrity]. Script tags with a literal external src, then
     * external .js URLs and `.src = '...'` assignments inside inline scripts (loader snippets). Blade/HTML comments are
     * ignored; a Blade expression inside a URL becomes empty (so `?id={{ $x }}` matches `?id=*`).
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function scan(string $source): array
    {
        $source = (string) preg_replace(['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s'], '', $source);
        $out = [];

        $rest = (string) preg_replace_callback('/<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/is', function (array $m) use (&$out) {
            if (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $m[0], $src) && self::isExternal($src[2])) {
                $out[] = [self::normalise($src[2]), (bool) preg_match('/\sintegrity\s*=\s*["\']sha(256|384|512)-/i', $m[0])];
            }

            return '<script>';
        }, $source);

        $literal = '(["\'])((?:https?:)?\/\/[^"\'\s]+?)\1';
        preg_match_all('/\.src\s*=\s*'.$literal.'|'.$literal.'/i', $rest, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $url = ($m[2] ?? '') !== '' ? $m[2] : ($m[4] ?? '');
            $isSrc = ($m[2] ?? '') !== '';
            if ($url === '' || ! self::isExternal($url)) {
                continue;
            }
            if ($isSrc || preg_match('/\.js(\?|$)/i', self::normalise($url))) {
                $out[] = [self::normalise($url), false];
            }
        }

        return $out;
    }

    private static function isExternal(string $url): bool
    {
        return (bool) preg_match('#^(https?:)?//#i', trim($url));
    }

    private static function normalise(string $url): string
    {
        $url = trim((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $url));

        return str_starts_with($url, '//') ? 'https:'.$url : $url;
    }

    /** @param  list<string>  $patterns */
    private static function matchesAny(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#i';
            if (preg_match($regex, $url)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> url glob => reason */
    private static function allowedPatterns(): array
    {
        $allowed = self::EXEMPT;
        $files = [dirname(__DIR__, 2).'/config/payment_scripts.php'];
        foreach (EditionPackages::all() as $package) {
            $file = $package['manifest']['payment_scripts'] ?? null;
            if (is_string($file)) {
                $files[] = $file;
            }
        }
        $inventory = false;
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $data = require $file;
            foreach (['scripts', 'excluded'] as $group) {
                foreach ((array) ($data[$group] ?? []) as $entry) {
                    if (is_array($entry) && is_string($entry['src'] ?? null) && self::isExternal($entry['src'])) {
                        $allowed[$entry['src']] = (string) ($entry['reason'] ?? 'PCI script inventory');
                        $inventory = true;
                    }
                }
            }
        }

        return $inventory ? $allowed : $allowed + self::INTERIM_INVENTORY;
    }

    /** @return list<string> */
    private static function viewFiles(): array
    {
        $files = [];
        foreach (ViewRoots::all() as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }
}
