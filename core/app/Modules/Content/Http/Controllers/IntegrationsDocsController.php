<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Juwri\Core\Http\Controllers\Controller;

/**
 * INTEGRATIONS-RUN: serves tenant-facing integration guidance docs (sender-name
 * registration, provider verification checklists) rendered from markdown. Slugs are
 * whitelisted so the route can never read arbitrary files.
 */
class IntegrationsDocsController extends Controller
{
    /** slug => [title, relative markdown path under resources/docs/integrations]. */
    private const DOCS = [
        'msegat-sender-registration' => ['Msegat sender-name registration', 'msegat-sender-registration.md'],
        'foodics-verification' => ['Foodics integration verification', 'foodics-verification.md'],
        'square-marketplace' => ['Square marketplace requirements', 'square-marketplace.md'],
        'deliverect-pos-track' => ['Deliverect POS-track certification', 'deliverect-pos-track.md'],
        'sunmi-kiosk' => ['Sunmi kiosk printing + device setup', 'sunmi-kiosk.md'],
        // PARITY-RUN T7 — India marketplaces.
        'zomato-partner-api' => ['Zomato integration setup', 'zomato-partner-api.md'],
        'swiggy-partner-api' => ['Swiggy integration setup', 'swiggy-partner-api.md'],
        'pos-bridge-square-clover' => ['POS bridge — Square & Clover', 'pos-bridge-square-clover.md'],
        'marn-pos' => ['Marn two-way POS', 'marn-pos.md'],
        'courier-dispatch' => ['Courier dispatch & live map', 'courier-dispatch.md'],
        'ai-phone-agent' => ['AI phone agent setup', 'ai-phone-agent.md'],
        'aggregator-hub' => ['Aggregator hub — Jahez / Keeta / Uber Eats', 'aggregator-hub.md'],
        'gloriafood-import' => ['GloriaFood migration import', 'gloriafood-import.md'],
        'google-business-ordering' => ['Google Business ordering link', 'google-business-ordering.md'],
        // INTEGRATIONS-PACK D3/D6 — no-code automation connectors.
        'zapier' => ['Zapier — connect your restaurant to 6,000+ apps', 'zapier.md'],
        'make' => ['Make — visual automation scenarios', 'make.md'],
        'n8n' => ['n8n — self-hosted automation', 'n8n.md'],
    ];

    public function show(string $slug)
    {
        abort_unless(isset(self::DOCS[$slug]), Response::HTTP_NOT_FOUND);

        [$title, $file] = self::DOCS[$slug];
        $path = resource_path('docs/integrations/'.$file);
        abort_unless(is_file($path), Response::HTTP_NOT_FOUND);

        $md = (string) file_get_contents($path);

        // D4-4: strip the YAML front-matter block so it never renders as content.
        if (str_starts_with($md, "---\n") && ($end = strpos($md, "\n---\n", 4)) !== false) {
            $md = substr($md, $end + 5);
        }

        // D2-5: repo-authored today, but never allow raw HTML or unsafe link
        // schemes through the docs pipeline — closes the latent XSS class for good.
        $html = Str::markdown($md, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $safeTitle = e($title);

        $body = <<<HTML
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$safeTitle}</title>
<style>
  body{max-width:760px;margin:2rem auto;padding:0 1rem;font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1a1a1a}
  h1,h2{line-height:1.25} code{background:#f2f2f2;padding:.1em .3em;border-radius:4px}
  a{color:#0b5fff} ol,ul{padding-left:1.3rem}
</style>
</head><body>
{$html}
</body></html>
HTML;

        return response($body, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300'); // D2-6
    }
}
