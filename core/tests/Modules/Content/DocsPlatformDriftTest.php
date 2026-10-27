<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Http\Controllers\IntegrationsDocsController;
use App\Http\Middleware\Installed;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DOCS-PLATFORM D4-1/2/3: the drift gates the pre-existing ApiDocsDriftTest did
 * NOT cover. Since fix A3 the /api/v1 index is router-generated (so that test is
 * effectively tautological); the hand-written .md files are the only API-doc
 * surface that can still drift. Plus a webhook-event-name parity gate (would have
 * caught the app/uninstalled vs app.uninstalled bug, D0-2) and a 1:1 check on the
 * integrations doc registry.
 */
class DocsPlatformDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /** Param-name-agnostic signature: METHOD + uri with {name} collapsed to {}. */
    private function signature(string $method, string $uri): string
    {
        return strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', ltrim($uri, '/'));
    }

    /** Every registered route's structural signature (GET/POST/PUT/PATCH/DELETE). */
    private function registeredSignatures(): array
    {
        $sigs = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $m) {
                if (in_array($m, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $sigs[$this->signature($m, $route->uri())] = true;
            }
        }

        return $sigs;
    }

    /**
     * D4-1: every `METHOD /api/v1/...` written in the dev API docs must resolve to
     * a registered route. Catches a documented endpoint that was renamed/removed.
     */
    public function test_every_documented_api_v1_endpoint_resolves(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $registered = $this->registeredSignatures();
        $files = glob(resource_path('docs/dev/api/*.md'));
        $this->assertNotEmpty($files, 'dev API docs exist');

        $unresolved = [];
        $checked = 0;
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            // inline, backticked, and table-cell forms: METHOD /api/v1/....
            preg_match_all(
                '#\b(GET|POST|PUT|PATCH|DELETE)\s+`?/?(api/v1/[A-Za-z0-9/{}._-]+)`?#',
                $text,
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as $hit) {
                $uri = rtrim($hit[2], '.,);`');
                // docs sometimes write "login|pin" shorthand for two routes — split.
                foreach (explode('|', $uri) as $variant) {
                    $variant = trim($variant);
                    if ($variant === '' || str_contains($variant, ' ')) {
                        continue;
                    }
                    // a shorthand tail like ".../staff/login|pin" → second part is a leaf
                    if (! str_starts_with($variant, 'api/v1/')) {
                        continue;
                    }
                    $checked++;
                    $sig = $this->signature($hit[1], $variant);
                    if (! isset($registered[$sig])) {
                        $unresolved[$sig] = basename($file);
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'the docs mention a meaningful number of endpoints');
        $this->assertSame(
            [],
            $unresolved,
            "Documented /api/v1 endpoints with no matching route:\n - ".
                implode("\n - ", array_map(fn ($s, $f) => "$s ($f)", array_keys($unresolved), $unresolved))
        );
    }

    /**
     * D4-2: every event named in the webhooks-events.md catalog table must be a
     * real fired/subscribable event (SELECTABLE_EVENTS + the `ping` test event).
     */
    public function test_webhook_event_catalog_matches_code(): void
    {
        $file = resource_path('docs/dev/api/webhooks-events.md');
        $this->assertFileExists($file);

        // Parse ONLY the "## Event catalog" table's event column: each row is
        // `| \`event.name\` | description |`, take the first backticked token so a
        // `data.message` mentioned inside a row's prose is not read as an event.
        $documented = [];
        $inCatalog = false;
        foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
            if (str_starts_with($line, '## ')) {
                $inCatalog = str_contains($line, 'Event catalog');

                continue;
            }
            if ($inCatalog && preg_match('/^\|\s*`([a-z]+\.[a-z_]+)`\s*\|/', $line, $m)) {
                $documented[] = $m[1];
            }
        }
        $documented = array_values(array_unique($documented));
        $this->assertNotEmpty($documented, 'the catalog documents events');

        // Known = dashboard-subscribable events + the delivery lifecycle events
        // (fired by DeliveryService, subscribable only by marketplace apps) + ping.
        $known = array_merge(
            WebhookEndpoint::SELECTABLE_EVENTS,
            ['order.driver_assigned', 'order.out_for_delivery', 'order.delivered', 'order.delivery_failed', 'ping']
        );
        $unknown = array_values(array_diff($documented, $known));

        $this->assertSame(
            [],
            $unknown,
            'webhooks-events.md documents event names that no code fires / accepts: '.implode(', ', $unknown)
        );
    }

    /**
     * D4-3: the integrations doc registry and the on-disk files stay 1:1 — a slug
     * without a file (or a file without a slug) fails here.
     */
    public function test_integrations_doc_registry_is_one_to_one(): void
    {
        $ref = new \ReflectionClass(IntegrationsDocsController::class);
        /** @var array<string,array{0:string,1:string}> $docs */
        $docs = $ref->getConstant('DOCS');

        $registered = [];
        foreach ($docs as $entry) {
            $registered[$entry[1]] = true;
        }
        $onDisk = array_map('basename', glob(resource_path('docs/integrations/*.md')));

        $missingFile = array_values(array_diff(array_keys($registered), $onDisk));
        $unregistered = array_values(array_diff($onDisk, array_keys($registered)));

        $this->assertSame([], $missingFile, 'registered integration docs with no file: '.implode(', ', $missingFile));
        $this->assertSame([], $unregistered, 'integration doc files not in the registry: '.implode(', ', $unregistered));
    }

    /**
     * D1-9: each integration that has an owner-facing settings screen links its
     * setup guide (route integrations.docs). Locks the wiring so a future view
     * edit that drops the link fails CI.
     */
    public function test_integration_screens_link_their_setup_guide(): void
    {
        // owner screen (view path under resources/views) => integration doc slug.
        // deliverect-pos-track and square-marketplace are partner/certification
        // docs with no owner screen and are intentionally excluded.
        $wired = [
            'owner/integrations/posbridge/foodics' => 'foodics-verification',
            'owner/integrations/posbridge/marn' => 'marn-pos',
            'owner/integrations/posbridge/index' => 'pos-bridge-square-clover',
            'owner/integrations/aggregators' => 'aggregator-hub',
            'owner/import/gloriafood' => 'gloriafood-import',
            'owner/phone-agent/index' => 'ai-phone-agent',
            'owner/gbp/index' => 'google-business-ordering',
            'owner/dispatch/couriers' => 'courier-dispatch',
            'owner/kiosk/index' => 'sunmi-kiosk',
        ];

        $missing = [];
        foreach ($wired as $view => $slug) {
            $path = resource_path('views/'.$view.'.blade.php');
            if (! is_file($path)) {
                $missing[] = "$view (view missing)";

                continue;
            }
            $src = (string) file_get_contents($path);
            if (! preg_match('/integrations\.docs[\'"],\s*[\'"]'.preg_quote($slug, '/').'[\'"]/', $src)) {
                $missing[] = "$view → $slug";
            }
        }

        $this->assertSame([], $missing, "integration screens missing their setup-guide link:\n - ".implode("\n - ", $missing));
    }

    /**
     * D4-4: every doc page (not README) carries front-matter with title, audience
     * and an updated date, so the docs:staleness report and any renderer can rely
     * on it. A new page without front-matter fails here.
     */
    public function test_every_doc_page_has_front_matter(): void
    {
        $base = resource_path('docs');
        // The Liquid reference under themes/ is machine-generated by
        // docs:build-reference (drift-gated by DevMcpReferenceTest); the generator
        // emits no front-matter, so those files are exempt here.
        $generated = ['dev/themes/drops.md', 'dev/themes/filters.md', 'dev/themes/tags.md'];
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'md' || $file->getFilename() === 'README.md') {
                continue;
            }
            $md = (string) file_get_contents($file->getPathname());
            $rel = str_replace($base.'/', '', $file->getPathname());
            if (in_array($rel, $generated, true)) {
                continue;
            }

            if (! str_starts_with($md, "---\n") || ($end = strpos($md, "\n---\n", 4)) === false) {
                $missing[] = "$rel (no front-matter)";

                continue;
            }
            $fm = substr($md, 4, $end - 4);
            foreach (['title', 'audience', 'updated'] as $field) {
                if (! preg_match('/^'.$field.':\s*\S/m', $fm)) {
                    $missing[] = "$rel (missing $field)";
                }
            }
        }

        $this->assertSame([], $missing, "docs missing front-matter fields:\n - ".implode("\n - ", $missing));
    }

    /**
     * D4-5: every relative markdown link between doc pages resolves to a real
     * file. Catches a renamed/deleted page that another doc still links to.
     */
    public function test_relative_doc_links_resolve(): void
    {
        $base = resource_path('docs');
        $broken = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $dir = dirname($file->getPathname());
            $rel = str_replace($base.'/', '', $file->getPathname());
            $text = (string) file_get_contents($file->getPathname());

            // [label](target.md) or [label](../pillar/target.md#anchor) — skip
            // http(s), mailto, in-page #anchors and non-.md links.
            preg_match_all('/\]\(([^)]+\.md)(#[^)]*)?\)/', $text, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $target = $hit[1];
                if (preg_match('#^(https?:|mailto:|/)#', $target)) {
                    continue;
                }
                $resolved = realpath($dir.'/'.$target);
                if ($resolved === false || ! is_file($resolved)) {
                    $broken[] = "$rel → $target";
                }
            }
        }

        $this->assertSame([], $broken, "broken relative doc links:\n - ".implode("\n - ", $broken));
    }

    /**
     * D4-7: every screenshot a doc embeds from /assets/docs/img/ must exist on
     * disk. The docs corpus references committed PNGs generated by the nightly
     * docs-screenshots e2e journey; a deleted or renamed asset would render a
     * broken image in the Help Center. The app's document root is the repo root
     * (one level above base_path(), which is core/), so /assets/... maps there.
     */
    public function test_embedded_doc_screenshots_exist(): void
    {
        $base = resource_path('docs');
        $repoRoot = base_path('..');
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $rel = str_replace($base.'/', '', $file->getPathname());
            $text = (string) file_get_contents($file->getPathname());

            // ![alt](/assets/docs/img/foo.png) — absolute, served from the repo root.
            preg_match_all('#\]\((/assets/[^)]+\.(?:png|jpe?g|webp|svg))\)#i', $text, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $target = $repoRoot.$hit[1];
                if (! is_file($target)) {
                    $missing[] = "$rel → {$hit[1]}";
                }
            }
        }

        $this->assertSame([], $missing, "docs embed screenshots that are missing on disk:\n - ".implode("\n - ", $missing));
    }
}
