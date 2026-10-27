<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;

/**
 * DOCS-PLATFORM D6-4: the changelog surface — a versioned, product-filterable
 * feed of user-facing changes, served as HTML, JSON, and RSS so resellers can
 * rebrand and forward it. Entries live in resources/docs/changelog.json. Breaking
 * changes are flagged. Brand-aware via brand_title().
 */
class ChangelogController extends Controller
{
    /** @return array<int,array<string,mixed>> newest first, optionally product-filtered */
    private function entries(?string $product = null): array
    {
        // Serve the Arabic overlay to an Arabic reader (same idiom as the docs
        // pillars): docs/ar/changelog.json when present, else the English source.
        $path = resource_path('docs/changelog.json');
        if (function_exists('is_rtl') && is_rtl() && app()->getLocale() === 'ar'
            && is_file($ar = resource_path('docs/ar/changelog.json'))) {
            $path = $ar;
        }
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];

        if ($product) {
            $entries = array_values(array_filter($entries, fn ($e) => ($e['product'] ?? '') === $product));
        }
        usort($entries, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        return $entries;
    }

    /**
     * Distinct product tags for the filter chips.
     *
     * @return array<int,string>
     */
    private function products(): array
    {
        $p = array_values(array_unique(array_map(fn ($e) => $e['product'] ?? '', $this->entries())));
        sort($p);

        return array_filter($p);
    }

    public function index(Request $request): Response
    {
        $product = $request->query('product');
        $product = is_string($product) && $product !== '' ? $product : null;

        // Rendered inside the marketing landing chrome (shared nav + footer +
        // design), matching the Help Center. JSON/RSS below stay machine formats.
        // active_theme() is passed explicitly: the global `*` composer that shares
        // it is off when APP_INSTALLED=false (the test env).
        return response()
            ->view('marketing.docs.changelog', [
                'activeTheme' => active_theme(),
                'brand' => brand_title(),
                'base' => url('/changelog'),
                'entries' => $this->entries($product),
                'products' => $this->products(),
                'activeProduct' => $product,
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function json(Request $request): JsonResponse
    {
        $product = $request->query('product');
        $product = is_string($product) && $product !== '' ? $product : null;

        return response()->json([
            'brand' => brand_title(),
            'entries' => $this->entries($product),
        ], 200, ['Cache-Control' => 'public, max-age=300'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function rss(Request $request): Response
    {
        $product = $request->query('product');
        $product = is_string($product) && $product !== '' ? $product : null;
        $brand = e(brand_title());
        $self = e(url('/changelog.rss'));
        $link = e(url('/changelog'));

        $items = '';
        foreach ($this->entries($product) as $e) {
            $title = e(($e['breaking'] ?? false ? '[BREAKING] ' : '').($e['title'] ?? ''));
            $desc = e($e['body'] ?? '');
            $guid = e(url('/changelog').'#'.($e['version'] ?? md5(($e['title'] ?? ''))));
            // RFC-822 date; the source date is a plain Y-m-d.
            $pub = e(($e['date'] ?? '2026-01-01').'T00:00:00+00:00');
            $items .= "<item><title>{$title}</title><link>{$link}</link><guid isPermaLink=\"false\">{$guid}</guid>"
                .'<category>'.e($e['product'] ?? '')."</category><pubDate>{$pub}</pubDate>"
                ."<description>{$desc}</description></item>";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel>'
            ."<title>{$brand} changelog</title><link>{$link}</link>"
            .'<description>Product updates</description>'
            ."<atom:link xmlns:atom=\"http://www.w3.org/2005/Atom\" href=\"{$self}\" rel=\"self\" type=\"application/rss+xml\"/>"
            .$items
            .'</channel></rss>';

        return response($xml, 200)
            ->header('Content-Type', 'application/rss+xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
