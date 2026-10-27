<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Models\WebhookEndpoint;
use App\Services\AppSessionToken;
use Juwri\Modules\Storefront\Storefront\Tags\CommerceTag;
use Juwri\Modules\Storefront\Storefront\Tags\SchemaTag;
use Juwri\Modules\Storefront\Storefront\Tags\WidgetTag;
use Juwri\Modules\Storefront\Storefront\ThemeFilters;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;
use App\Support\LandingChrome;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Keepsuit\Liquid\Attributes\Hidden;
use Keepsuit\Liquid\Drop;
use Laravel\Passport\Passport;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Juwri\Core\Http\Controllers\Controller;

/**
 * Platform P5: machine-readable API docs for AI agents. `/llms.txt` is a stable,
 * plain-text summary of the public API (Shopify AI-toolkit pattern) so an agent can
 * integrate correctly without scraping the human docs at /developers.
 */
class DeveloperDocsController extends Controller
{
    /**
     * GET /developers — the human REST API + webhooks reference. Extends the home
     * landing layout so it carries the exact site nav + footer (was a bespoke
     * route closure passing only doc data before).
     */
    public function index(): View
    {
        return view('developers', [
            'baseUrl' => url('/api/v1'),
            'mcpUrl' => url('/mcp'),
            'devMcpUrl' => url('/mcp/dev'), // rev/dev-mcp: public docs/reference MCP
            'siteName' => brand_title(),    // RP4: reseller brand on a reseller host
        ] + LandingChrome::data());
    }

    public function llms()
    {
        $base = rtrim(config('app.url'), '/');
        $name = brand_title(); // RP4: reseller brand on a reseller host

        // rev/seo R21: lead with a product summary + key page links so AI answer
        // engines (ChatGPT/Perplexity/Claude search) can describe and cite the
        // product. Localized landing links help them surface the right-language
        // page. The API/dev reference for agents follows below, untouched.
        $marketing = $this->llmsMarketing($base, function_exists('seo_brand') ? seo_brand() : $name);

        $txt = <<<TXT
{$marketing}

# {$name} — API for AI agents

> Public API for browsing restaurant menus and placing orders. Base URL: {$base}

## Auth
- Storefront API (public, read): header `X-Storefront-Token: <token>` (scope menu:read). Per-restaurant; safe to embed.
- Customer API (ordering): Bearer customer token from register/login/OTP.

## Endpoints
- GET {$base}/api/v1/storefront/menu — full menu: categories → items (id, name, price, variants[], extras[], variant_groups[], gallery[], flags).
- GET {$base}/api/v1/storefront/restaurant — name, hours-adjacent info.
- POST {$base}/api/v1/customer/orders — place an order. Body: {"type":"takeaway|on-table|delivery","client_uuid":"<uuid>","items":[{"item_id":N,"quantity":N,"variation":N?,"extras":[N]}]}. `client_uuid` makes retries idempotent.
- GET {$base}/api/v1/track/{track_token} — order status.

## Rules
- Prices are computed server-side from the menu; never send prices.
- Rate limit: 120 requests/min per token (429 on exceed).
- A variant id (variation) and extra ids come from the /menu payload.

## Staff app auth (per-staff Bearer token)
- POST {$base}/api/v1/staff/push/register-device — register a device for native push (body: token, platform). Unregister at .../push/unregister-device.
- POST {$base}/api/v1/staff/2fa/setup then .../2fa/confirm — optional TOTP enrolment for an email-login staff account.

## Webhooks (outbound events)
- Manage endpoints with an owner token: GET/POST {$base}/api/v1/webhook-endpoints, DELETE .../webhook-endpoints/{id} (scopes webhooks:read / webhooks:write).
- Subscribable events include order.created, order.status_changed, order.paid, refund.completed, reservation.created, reservation.cancelled, customer.created, shift.opened, shift.closed, menu.updated, entitlement.changed, the subscription.* family, and app.uninstalled — or `*` for all. Signed with Standard Webhooks (webhook-signature header). Full catalog + payloads: {$base}/developers and {$base}/help-center (dev/api/webhooks-events).

## Human docs
- [Developers]({$base}/developers): REST API + webhooks reference.
- [Build & sell an app]({$base}/developers/apps): OAuth, scopes, session tokens, webhooks, usage billing.
- [Build & sell a theme]({$base}/developers/themes): marketplace theme development.

## Help centre (restaurant-owner guides)
- [Help centre]({$base}/help-center): index of owner how-to guides. Append `.md` to any page (e.g. {$base}/help-center/getting-started.md), or send an `Accept: text/markdown` header, for raw markdown.
- [Getting started]({$base}/help-center/getting-started.md): first menu to first order.
- [QR codes]({$base}/help-center/qr-codes.md): QR menu setup.
- [POS]({$base}/help-center/pos.md): point of sale.
- [Orders]({$base}/help-center/orders.md): taking and managing orders.
- [Plans & billing]({$base}/help-center/plans-billing.md): plans, add-ons, billing.

## Dev MCP (for AI coding tools)
POST {$base}/mcp/dev — public JSON-RPC MCP server: searchable docs, generated
Liquid theme reference, server-side theme validation, and list_webhook_events (the
authoritative outbound webhook event catalog). Connect instructions:
{$base}/developers#ai-editor

## Catalog MCP (discover restaurants across the platform)
POST {$base}/mcp/catalog — public JSON-RPC MCP server: search_stores, search_items
(cross-store dish search), get_store, list_starter_menus (the bundled starter-menu
presets a new store can seed from). Use it to find where to order, then use a
store's storefront MCP below to place an unpaid order.

## Storefront MCP (browse + order at one restaurant)
POST {$base}/mcp/storefront — public JSON-RPC MCP server, scoped to one restaurant
(pass the store from Catalog MCP). Tools: search_menu, get_item, restaurant_info,
dietary_answer, suggest_upsell, validate_cart, create_order (unpaid), order_status,
order_eta, order_history, reorder_last, store_credit_balance, hotel_availability
(room-type availability + nightly price when the venue is a bookable hotel). Prices
are computed server-side; never send prices.

## Customer MCP (a signed-in diner's own account)
POST {$base}/mcp/customer — JSON-RPC MCP server authenticated with a customer Bearer
token (from register/login/OTP). Tools: my_profile, my_orders, order_detail, reorder,
my_bookings (the diner's own hotel room bookings) — the customer's own order history,
bookings and account, not public data.
TXT;

        // D2-6: agents re-fetch llms.txt often; short public cache saves the
        // per-hit route/locale computation without risking staleness.
        return response($txt, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * D2-3: /.well-known/mcp.json — discovery document advertising the public
     * MCP servers (brand-aware names on reseller hosts). Lets an MCP client find
     * the servers without scraping the docs.
     */
    public function mcpDiscovery(): JsonResponse
    {
        // Host-relative URLs (url() honours the current host) so a reseller host
        // advertises its OWN endpoints, not the platform origin — matching the
        // brand-aware server names and the /mcp landing page. config('app.url')
        // would hardcode the platform domain on every white-label host.
        $servers = [
            ['name' => mcp_server_name('dev-mcp'), 'url' => url('/mcp/dev'), 'description' => 'Build on the platform: searchable docs, Liquid theme reference, theme validation.', 'auth' => 'none'],
            ['name' => mcp_server_name('catalog-mcp'), 'url' => url('/mcp/catalog'), 'description' => 'Discover stores across the platform (search stores + products, filter by business type).', 'auth' => 'none'],
            ['name' => mcp_server_name('storefront-mcp'), 'url' => url('/mcp/storefront'), 'description' => 'Browse one store and place an unpaid order in its fulfillment mode (dine-in, takeaway, ship, pickup, booking).', 'auth' => 'storefront-token'],
            ['name' => mcp_server_name('customer-mcp'), 'url' => url('/mcp/customer'), 'description' => "A signed-in customer's own account and order history.", 'auth' => 'customer-token'],
            ['name' => mcp_server_name('mcp'), 'url' => url('/mcp'), 'description' => 'Admin API over MCP (manage the catalog, orders, and more across any business type).', 'auth' => 'api-token'],
        ];

        return response()->json(
            ['schema' => 'https://modelcontextprotocol.io/.well-known/mcp.json', 'servers' => $servers],
            200,
            ['Cache-Control' => 'public, max-age=300'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * rev/seo R21: the product/marketing block at the top of /llms.txt.
     * Localized landing links are emitted only in URL-prefix locale mode
     * (otherwise every locale shares one URL and listing them would mislead).
     */
    private function llmsMarketing(string $base, string $name): string
    {
        $home = rtrim(route('home'), '/');
        $pricing = Route::has('pricing') ? route('pricing') : $home;
        $studio = Route::has('studio') ? route('studio') : null;
        $blog = Route::has('blog.index') ? route('blog.index') : null;
        // SEO run W6.3: surface the marketing feature/theme pages and the free
        // public tools so answer engines can cite and deep-link them.
        $features = Route::has('features') ? route('features') : null;
        $themes = Route::has('themes') ? route('themes') : null;
        $calc = Route::has('tools.commission-calculator') ? route('tools.commission-calculator') : null;
        $qrgen = Route::has('tools.qr-generator') ? route('tools.qr-generator') : null;
        $scanner = Route::has('tools.menu-scanner') ? route('tools.menu-scanner') : null;

        $lines = [
            "# {$name}",
            '',
            "> {$name} turns a restaurant's paper menu into a free digital QR-code menu with online ordering. Diners scan a QR code, browse the menu on their own phone, and send the order straight to the restaurant's WhatsApp — no app to download, no per-order commission.",
            '',
            '## What it does',
            '- Free QR-code menu for restaurants, cafes and shops (create and publish at no cost).',
            '- Orders delivered to WhatsApp; optional online payments, delivery and dine-in table ordering.',
            '- AI menu builder (Menu Studio): turn a photo, PDF or handwritten menu into an online-ordering menu or a print-ready designed PDF.',
            '- Multi-branch, POS, kitchen display, inventory, loyalty and analytics for growing restaurants.',
            '- Available in 11 languages (Arabic, English, German, Spanish, French, Hindi, Portuguese, Russian, Turkish, Urdu, Chinese).',
            '',
            '## Key pages',
            "- [Home]({$home}): QR-code menus and WhatsApp ordering — overview.",
            "- [Pricing]({$pricing}): plans, add-ons and the free tier.",
        ];
        if ($features) {
            $lines[] = "- [Features]({$features}): QR menus, WhatsApp ordering, POS, kitchen, analytics, loyalty, e-invoicing.";
        }
        if ($themes) {
            $lines[] = "- [Themes]({$themes}): storefront menu designs with live demos.";
        }
        if ($studio) {
            $lines[] = "- [Menu Studio]({$studio}): AI menu builder — a photo or PDF becomes a digital menu.";
        }
        if ($blog) {
            $lines[] = "- [Blog / guides]({$blog}): how-tos and product guides.";
        }

        // SEO run W6.3: free public tools (no login).
        $tools = array_filter([
            $calc ? "- [Delivery commission calculator]({$calc}): what aggregators like Yemeksepeti, iFood, Talabat and Uber Eats really cost vs a flat subscription." : null,
            $qrgen ? "- [QR code menu generator]({$qrgen}): print-ready PNG/SVG for any menu link." : null,
            $scanner ? "- [AI menu scanner]({$scanner}): turn a photo of a paper menu into a digital menu." : null,
        ]);
        if ($tools) {
            $lines[] = '';
            $lines[] = '## Free tools (no login required)';
            foreach ($tools as $t) {
                $lines[] = $t;
            }
        }

        // SEO run W6.3: pricing model, compliance and quotable facts so GEO/answer
        // engines can summarise the product accurately.
        $lines[] = '';
        $lines[] = '## Pricing model';
        $lines[] = '- Flat monthly/annual subscription with a free tier. 0% commission on orders — the restaurant keeps 100% of every sale on its own QR, WhatsApp and website channel.';
        $lines[] = '';
        $lines[] = '## E-invoicing compliance';
        $lines[] = '- Live fiscal drivers for ZATCA (Saudi Arabia), JoFotara (Jordan) and ETA (Egypt), with Phase-2 QR receipts; plus tax support across UAE, Qatar, Kuwait, Bahrain, Oman, Morocco, Tunisia and Algeria.';
        $lines[] = '';
        $lines[] = '## Quotable facts';
        $lines[] = '- 27 payment gateways, 11 tax authorities and 7 AI providers; interface available in 11 languages.';
        $lines[] = '- 19 storefront menu themes with live demos.';
        $lines[] = '- Commission-free: a flat subscription instead of a per-order cut from delivery aggregators.';
        $lines[] = '- Order channels: dine-in QR, pickup, delivery, curbside and WhatsApp.';

        // SEO run W6.3: platform agent-discovery manifests (per-restaurant agent
        // surfaces at /agent/{id}/manifest and /agent/{id}/llms.txt).
        $lines[] = '';
        $lines[] = '## Agent discovery';
        if (Route::has('mcp.landing')) {
            $lines[] = "- MCP overview & setup: {$base}/mcp";
        }
        $lines[] = "- MCP discovery manifest: {$base}/.well-known/mcp.json";
        $lines[] = "- UCP agent manifest: {$base}/.well-known/ucp";

        // Localized homes (prefix mode only — real per-language URLs exist).
        if (config('settings.include_language_code') && function_exists('get_active_languages')) {
            $localized = [];
            foreach (array_keys(get_active_languages()) as $code) {
                $url = LaravelLocalization::getLocalizedURL($code, $home, [], false);
                if ($url) {
                    $localized[] = $code.': '.rtrim($url, '/');
                }
            }
            if ($localized) {
                $lines[] = '';
                $lines[] = '## Localized home pages';
                foreach ($localized as $l) {
                    $lines[] = '- '.$l;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * P2-7: theme developer documentation. The Drop reference is GENERATED by
     * reflection over the actual Drop classes (same visibility rules the engine
     * applies: public, non-static, 0-arg, not #[Hidden]) so it can never drift
     * from what a template can really touch.
     */
    public function themes()
    {
        return view('developers-themes', [
            'siteName' => brand_title(),
            'drops' => self::dropReference(),
            'filters' => self::filterReference(),
        ] + LandingChrome::data());
    }

    /**
     * stores P5-1: app-developer build docs. Scopes + webhook events + the
     * session-token TTL are REFLECTED from code so the reference cannot drift.
     */
    public function apps()
    {
        return view('developers-apps', [
            'siteName' => brand_title(),
            'baseUrl' => url('/api/v1'),
            'usageUrl' => url('/api/app/usage'),
            'appUrl' => url('/'),
            // P1-13 13h: the partner dashboard (app submission) is ee — no link to it in Community.
            'partnerUrl' => Route::has('partner.apps.create') ? url('/partner/apps/create') : null,
            'scopes' => collect(Passport::scopes())
                ->map(fn ($s) => ['id' => $s->id, 'desc' => $s->description])->values()->all(),
            // HPMS P2: reflect the subscribable event names from the platform's own
            // registry (WebhookEndpoint::SELECTABLE_EVENTS, minus the `*` wildcard) so
            // the /developers/apps event list can never drift — it now surfaces this
            // run's new refund/reservation/customer/shift lifecycle events automatically.
            'events' => array_values(array_filter(
                WebhookEndpoint::SELECTABLE_EVENTS,
                fn ($e) => $e !== '*'
            )),
            'sessionTtl' => AppSessionToken::TTL_SECONDS,
        ] + LandingChrome::data());
    }

    /**
     * P2-7: the downloadable starter theme — zipped from the in-repo skeleton
     * and self-checked through the package validator, so the download is
     * guaranteed to be an installable example of the documented format.
     */
    public function starterZip()
    {
        $dir = resource_path('liquid-themes/starter/1');
        abort_unless(is_dir($dir), 404);

        // D2-6: unique temp path per request — the old fixed path let concurrent
        // downloads unlink each other's zip mid-build/mid-send.
        $zipPath = storage_path('app/starter-theme-'.Str::random(12).'.zip');

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
                $zip->addFile($file->getPathname(), $rel);
            }
        }
        $zip->close();

        // Never ship a starter that our own validator would reject.
        ThemePackageValidator::assertSafe($zipPath);

        return response()->download($zipPath, 'starter-theme.zip')->deleteFileAfterSend(true);
    }

    /**
     * Reflection over app/Storefront/Drops — the engine's own exposure rules.
     *
     * @return array<string, list<array{name:string,type:string}>>
     */
    public static function dropReference(): array
    {
        $out = [];
        $baseMethods = array_map(
            fn ($m) => $m->getName(),
            new \ReflectionClass(Drop::class)->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        foreach (glob(app_path('Modules/Storefront/Storefront/Drops/*.php')) ?: [] as $file) {
            $class = 'Juwri\\Modules\\Storefront\\Storefront\\Drops\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }

            $props = [];
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (
                    $method->isStatic()
                    || $method->getNumberOfParameters() !== 0
                    || str_starts_with($method->getName(), '__')
                    || in_array($method->getName(), $baseMethods, true)
                    || $method->getAttributes(Hidden::class) !== []
                ) {
                    continue;
                }
                $props[] = [
                    'name' => Str::snake($method->getName()),
                    'type' => (string) ($method->getReturnType() ?? 'mixed'),
                ];
            }
            sort($props);
            $out[$ref->getShortName()] = $props;
        }
        ksort($out);

        return $out;
    }

    /**
     * The whitelisted theme filters, reflected from ThemeFilters (public,
     * non-hidden methods — the registry's own rule).
     *
     * @return list<string>
     */
    public static function filterReference(): array
    {
        $ref = new \ReflectionClass(ThemeFilters::class);
        $names = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (
                $method->isStatic()
                || str_starts_with($method->getName(), '__')
                || $method->getDeclaringClass()->getName() !== ThemeFilters::class
                || $method->getAttributes(Hidden::class) !== []
            ) {
                continue;
            }
            $names[] = Str::snake($method->getName());
        }
        sort($names);

        return $names;
    }

    /**
     * Dev MCP P2-1: the custom tags the engine registers, discovered from
     * LiquidEnvironmentFactory's own registrations (tagName() is the engine's
     * source of truth — names here can never drift from what parses). Syntax
     * and examples are the only hand-written part, keyed by tag name so a
     * missing entry is visible instead of silently wrong.
     *
     * @return list<array{name: string, kind: string, syntax: string, description: string, example: string}>
     */
    public static function tagReference(): array
    {
        $docs = [
            SchemaTag::class => [
                'kind' => 'block',
                'syntax' => '{% schema %} { ...JSON... } {% endschema %}',
                'description' => 'Section/settings schema block. The JSON body is parsed and validated at '
                    .'upload time (invalid JSON is a syntax error) and emits NOTHING at render — it is '
                    .'metadata for the theme editor. Body is raw: Liquid inside is not executed. '
                    .'At most one per section file; values reach the template via section.settings.',
                'example' => "{% schema %}\n{\n  \"name\": \"Hero\",\n  \"settings\": [\n    { \"type\": \"text\", \"id\": \"heading\", \"label\": \"Heading\", \"default\": \"Welcome\" }\n  ]\n}\n{% endschema %}",
            ],
            CommerceTag::class => [
                'kind' => 'inline',
                'syntax' => '{% commerce %}',
                'description' => 'Emits the platform commerce runtime (cart/checkout config + versioned '
                    .'commerce.js, CSP-nonced) exactly once per page — repeat uses emit nothing. Takes no '
                    .'arguments. Place once in the layout; themes never call ordering APIs directly.',
                'example' => '{% commerce %}',
            ],
            WidgetTag::class => [
                'kind' => 'inline',
                'syntax' => "{% widget 'name' %} or {% widget 'name', label: 'Text' %}",
                'description' => 'Renders an allow-listed platform widget — a trigger button plus its own '
                    .'accessible dialog, CSP-nonced JS and --tc-* token styling — for features that need '
                    .'server logic: '.implode(', ', WidgetTag::names()).'. The name must be a quoted '
                    .'literal; an unknown name is a syntax error at upload (and renders nothing live). '
                    .'Renders nothing when the store has the feature switched off (the same gate as '
                    .'`features.*`), so it is safe to place unconditionally. Only `label` is accepted as '
                    .'a parameter. Repeat uses keep every trigger but write the dialog once.',
                'example' => "{% widget 'reservation' %}\n{% widget 'loyalty', label: section.settings.loyalty_label %}",
            ],
        ];

        $out = [];
        foreach ($docs as $class => $doc) {
            $out[] = ['name' => $class::tagName()] + $doc;
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }
}
