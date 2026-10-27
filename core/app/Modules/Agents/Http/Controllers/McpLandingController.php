<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use App\Support\LandingChrome;
use Illuminate\Contracts\View\View;
use ReflectionClass;
use ReflectionMethod;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Agents\Mcp\McpToolCatalog;

/**
 * MCP-PAGE run (D2-9): the public `/mcp` landing page + `/.well-known/mcp.json`
 * discovery manifest.
 *
 * The page EXTENDS the home landing layout so it shares the exact site nav,
 * footer, fonts and theme (one design language with the home). No auth; served
 * on every host and brand-aware (DEC-5): platform hosts show `juwri-*` +
 * the platform brand, an active reseller host shows the reseller's brand +
 * slugged `mcp_server_name()` names (the layout's nav/footer read a brand-aware
 * settings object, so the platform name never leaks on a white-label domain).
 *
 * GET /mcp renders this page (DEC-2); the four sub-paths (/mcp/dev, /storefront,
 * /catalog, /customer) keep their GET 405 + Allow: POST. POST protocol behaviour
 * on all five endpoints is UNTOUCHED.
 */
class McpLandingController extends Controller
{
    /**
     * The five live MCP servers, in page order (public/zero-auth first so the
     * fastest path to value leads). `nameSuffix` feeds mcp_server_name();
     * `countSource` is [Controller::class, 'method'] whose returned tool array
     * is counted at build time — never a hardcoded number (protects against the
     * /developers 22-vs-23 drift class).
     *
     * @var array<int,array{key:string,path:string,nameSuffix:string,auth:string,countSource:array{0:class-string,1:string}}>
     */
    private const SERVERS = [
        ['key' => 'catalog', 'path' => '/mcp/catalog', 'nameSuffix' => 'catalog-mcp', 'auth' => 'public', 'label' => 'Catalog', 'countSource' => [McpToolCatalog::class, 'catalog']],
        ['key' => 'dev', 'path' => '/mcp/dev', 'nameSuffix' => 'dev-mcp', 'auth' => 'public', 'label' => 'Dev', 'countSource' => [McpToolCatalog::class, 'dev']],
        ['key' => 'admin', 'path' => '/mcp', 'nameSuffix' => 'mcp', 'auth' => 'api-token', 'label' => 'Admin', 'countSource' => [McpToolCatalog::class, 'owner']],
        ['key' => 'storefront', 'path' => '/mcp/storefront', 'nameSuffix' => 'storefront-mcp', 'auth' => 'storefront-token', 'label' => 'Storefront', 'countSource' => [McpToolCatalog::class, 'storefront']],
        ['key' => 'customer', 'path' => '/mcp/customer', 'nameSuffix' => 'customer-mcp', 'auth' => 'customer-token', 'label' => 'Customer', 'countSource' => [McpToolCatalog::class, 'customer']],
    ];

    /** Human-readable auth labels for the badges/tables (keyed by SERVERS[auth]). */
    private const AUTH_LABELS = [
        'public' => 'Public',
        'api-token' => 'API token',
        'storefront-token' => 'Storefront token',
        'customer-token' => 'Customer token',
    ];

    /** GET /mcp — the public landing page (extends the home landing layout so it
     *  shares the exact site nav, footer, fonts and theme). We pass activeTheme +
     *  languages + a BRAND-AWARE settings object explicitly, so the layout renders
     *  regardless of the frontend view composer (which is skipped when the app is
     *  not "installed", e.g. under the test runner) AND so the nav/footer show the
     *  reseller's own brand on a white-label host instead of the platform's. */
    public function show(): View
    {
        return view('mcp-landing', [
            'brandName' => brand_title(),
            'servers' => $this->servers(),
        ] + LandingChrome::data());
    }

    /**
     * Resolve the five servers with live, brand-aware names/URLs and live tool
     * counts. Shared by the page and (later) the discovery manifest.
     *
     * @return array<string,array{key:string,name:string,label:string,url:string,path:string,auth:string,authLabel:string,isPublic:bool,toolCount:int,cursorDeeplink:string,vscodeDeeplink:string,npxCmd:string}>
     */
    private function servers(): array
    {
        $out = [];
        foreach (self::SERVERS as $s) {
            $name = mcp_server_name($s['nameSuffix']);
            $url = url($s['path']);
            $out[$s['key']] = [
                'key' => $s['key'],
                'name' => $name,
                'label' => $s['label'],
                'url' => $url,
                'path' => $s['path'],
                'auth' => $s['auth'],
                'authLabel' => self::AUTH_LABELS[$s['auth']],
                'isPublic' => $s['auth'] === 'public',
                'toolCount' => $this->reflectCount($s['countSource'][0], $s['countSource'][1]),
                // One-click install deeplinks (RESEARCH §3). Cursor wants base64
                // of {"url":…}; VS Code wants URL-encoded {"type":"http","url":…}
                // (a different schema — the page says so). Built here so the blade
                // stays declarative and every URL is brand-aware.
                'cursorDeeplink' => 'cursor://anysphere.cursor-deeplink/mcp/install?name='.rawurlencode($name)
                    .'&config='.base64_encode((string) json_encode(['url' => $url])),
                'vscodeDeeplink' => 'https://vscode.dev/redirect/mcp/install?name='.rawurlencode($name)
                    .'&config='.rawurlencode((string) json_encode(['type' => 'http', 'url' => $url])),
                'npxCmd' => 'npx -y mcp-remote '.$url,
            ];
        }

        return $out;
    }

    /**
     * Count the entries a controller's tool-registry method returns, invoked on
     * an un-constructed instance (these methods are pure — no request/DB use, no
     * ability gating: McpToolCatalog::owner() is the FULL Admin list of the shared
     * agent tool registry before per-token hiding (P1-16); the public/token toolList()
     * builders take no args).
     * Counting the live registry is the source of truth against drift.
     *
     * @param  class-string  $class
     */
    private function reflectCount(string $class, string $method): int
    {
        $instance = new ReflectionClass($class)->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($class, $method);
        $m->setAccessible(true);

        return count((array) $m->invoke($instance));
    }
}
