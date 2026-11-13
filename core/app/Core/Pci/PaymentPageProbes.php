<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Pci;

use Closure;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * PCI DSS 4.0 11.6.1: renderers of payment pages for the change-detection job. A payment page normally needs a
 * pending payment of a real buyer, so the module that owns the page registers a renderer that shows the REAL view
 * (same layout, same scripts, same middleware and headers) with a synthetic, unsaved payment. The job fetches it
 * over HTTP through a signed, short-lived URL (route pci.probe), exactly as a browser would.
 *
 * A renderer returns the page Response, or null when it cannot render here (e.g. no store to show it for).
 */
final class PaymentPageProbes
{
    /** @var array<string, Closure(): ?Response> */
    private static array $renderers = [];

    /** @param Closure(): ?Response $renderer */
    public static function register(string $page, Closure $renderer): void
    {
        self::$renderers[$page] = $renderer;
    }

    /** @return list<string> pages with a probe */
    public static function pages(): array
    {
        return array_keys(self::$renderers);
    }

    public static function render(string $page): ?Response
    {
        $renderer = self::$renderers[$page] ?? null;

        return $renderer === null ? null : $renderer();
    }

    /** The store whose storefront payment pages the probes render: payment_scripts.monitor.probe_store (slug), else the oldest store. */
    public static function store(): ?Store
    {
        $slug = config('payment_scripts.monitor.probe_store');
        $query = Store::query();
        if (is_string($slug) && $slug !== '') {
            return $query->where('slug', $slug)->first();
        }

        return $query->orderBy('id')->first();
    }

    public static function url(string $page): string
    {
        return URL::temporarySignedRoute('pci.probe', now()->addMinutes(10), ['page' => $page]);
    }
}
