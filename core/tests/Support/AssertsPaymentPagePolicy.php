<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Juwri\Core\Pci\PaymentScriptInventory;
use Juwri\Core\Pci\ScriptSet;

/**
 * PCI DSS 6.4.3 test helper: a payment page response carries its strict, nonce-based, reporting CSP and its HTML
 * stays inside the script inventory (every inline script with this response's nonce).
 */
trait AssertsPaymentPagePolicy
{
    /** @return array<string, string> directive => value */
    protected static function directives(string $header): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $header))) as $part) {
            [$name, $value] = array_pad(explode(' ', $part, 2), 2, '');
            $out[$name] = $value;
        }

        return $out;
    }

    protected function assertStrictPaymentPolicy(TestResponse $res, string $page): array
    {
        $header = (string) $res->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $header, "{$page}: no enforced CSP");
        $d = self::directives($header);
        $script = $d['script-src'] ?? '';
        $this->assertMatchesRegularExpression("/^'self' 'nonce-[A-Za-z0-9+\\/=_-]+'/", $script, "{$page}: script-src");
        $this->assertStringNotContainsString("'unsafe-inline'", $script);
        $this->assertDoesNotMatchRegularExpression('/(^| )(https:|http:|\*|data:)( |$)/', $script, "{$page}: no scheme-wide script source");
        $this->assertSame("'none'", $d['object-src'] ?? null);
        $this->assertSame("'none'", $d['script-src-attr'] ?? null);
        $this->assertSame("'self'", $d['base-uri'] ?? null);
        $this->assertSame("'self'", $d['frame-ancestors'] ?? null);
        $this->assertSame(route('pci.csp-report', ['page' => $page]), $d['report-uri'] ?? null);
        $this->assertSame('payment-csp', $d['report-to'] ?? null);
        $this->assertSame('payment-csp="'.route('pci.csp-report', ['page' => $page]).'"', $res->headers->get('Reporting-Endpoints'));
        if (trim((string) (PaymentScriptInventory::page($page)['unsafe_eval'] ?? '')) === '') {
            $this->assertStringNotContainsString("'unsafe-eval'", $script, "{$page}: eval only where the inventory says why");
        }

        // The HTML is inside the inventory, and every inline script carries THIS response's nonce.
        $html = (string) $res->getContent();
        $this->assertSame([], PaymentScriptInventory::violations($html, $page));
        preg_match("/'nonce-([^']+)'/", $script, $m);
        foreach (ScriptSet::fromHtml($html)->inline() as $tag) {
            $this->assertTrue($tag->nonced);
        }
        $this->assertStringContainsString('nonce="'.$m[1].'"', $html);

        return $d;
    }
}
