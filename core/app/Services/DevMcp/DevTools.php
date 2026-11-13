<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\DevMcp;

use App\Http\Controllers\DeveloperDocsController;
use App\Models\WebhookEndpoint;
use Juwri\Modules\Agents\Http\Controllers\McpError;
use Juwri\Modules\Agents\Mcp\DefinedTool;
use Juwri\Modules\Agents\Mcp\McpToolSet;
use Juwri\Modules\Content\Services\DevDocs\DocsCorpus;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;

/**
 * Dev MCP (rev/dev-mcp) / P1-16: the tools of the developer-facing MCP server ({@see DevMcpServer}, POST /mcp/dev) —
 * moved unchanged from the former hand-rolled DevMcpController. Public and read-only by design — it serves only what
 * is already public (/developers, /llms.txt, the generated Liquid reference) so AI coding tools (Claude Code, Cursor)
 * can build themes against real, current platform facts instead of hallucinating. No auth, no tenant data, ever; rate
 * limiting and input-size caps are the only guards (spec §1, §2.6). Lives outside the modules: it reads Content
 * (docs corpus) and Storefront (theme linter), which the Agents module does not depend on.
 */
final class DevTools implements McpToolSet
{
    // --- Tool registry -----------------------------------------------------

    /** All tools are public; read-only but submit_feedback; the registry grows per spec §2. */
    public function definitions(): array
    {
        return [
            DefinedTool::define(
                'learn_platform',
                'Call this FIRST in any conversation about building on this platform. '
                .'Returns a primer on the platform: the three developer surfaces (Liquid themes, '
                .'Storefront API, Customer API), key endpoints, hard rules agents commonly get wrong, '
                .'and which tool to use next. Optional topic narrows the primer: '
                .'"themes" (theme package format + workflow), "apps" (build & sell a marketplace '
                .'app — OAuth, scopes, session tokens, webhooks, usage billing), "storefront-api" '
                .'or "customer-api" (REST endpoints + invariants). Do not answer platform questions from memory — '
                .'after this primer, use search_docs for prose and get_liquid_reference for the exact API surface.',
                ['topic' => 'string']
            ),
            DefinedTool::define(
                'get_liquid_reference',
                'The authoritative whitelist of Liquid the theme engine supports, generated at request '
                .'time by reflection over the engine\'s own classes — never stale. Anything NOT listed here '
                .'(a Shopify filter, an invented object) will FAIL validation: do not use it. '
                .'Optional kind narrows output: "drops" (objects and their properties), "filters", '
                .'"tags" (with syntax + examples), default "all". '
                .'Use search_docs for prose/how-to; use this for the exact API surface.',
                ['kind' => 'string']
            ),
            DefinedTool::define(
                'get_section_schema',
                'The {% schema %} format for theme sections: JSON shape, every supported setting type '
                .'with its exact validation rule (unknown types fall back to text), blocks, '
                .'templates/index.json wiring, and a worked example from the starter theme. '
                .'Optional setting_type (e.g. "color", "select") returns just that type\'s rules. '
                .'Use get_liquid_reference for drops/filters/tags; use this for section settings.',
                ['setting_type' => 'string']
            ),
            DefinedTool::define(
                'search_docs',
                'Keyword search across ALL public docs — developer (theme format, APIs, webhooks, '
                .'validation, editor setup) AND the platform help pillars: users (restaurant-owner '
                .'guides), integrations, resellers, shoppers. Returns matching chunks with doc path + '
                .'section + snippet — broad research tool, use when unsure where to look. Follow up with '
                .'fetch_full_doc on a result\'s doc path for complete context. Optional pillar narrows '
                .'the search to one of: dev, users, integrations, resellers, shoppers (default "all"). '
                .'max_results caps results (default 5, max 10).',
                ['query' => 'string', 'max_results' => 'integer', 'pillar' => 'string'],
                ['query']
            ),
            DefinedTool::define(
                'fetch_full_doc',
                'Fetch one complete developer doc by its corpus path (as returned by search_docs, e.g. '
                .'"themes/validation", "api/webhooks"). Use after search_docs when a snippet lacks '
                .'context. Unknown paths error listing every valid path.',
                ['path' => 'string'],
                ['path']
            ),
            [
                'name' => 'validate_theme',
                'description' => 'Server-side validation of theme files using the SAME rules the marketplace '
                    .'applies at submission: strict whitelist Liquid parse, unknown filters/tags, missing '
                    .'snippets, external URLs, schema shape, dangerous markup, layout/extension rules. '
                    .'Pass files as {"sections/hero.liquid": "<source>", ...}. mode "files" (default) lints '
                    .'fragments; mode "package" additionally requires theme.json + templates entrypoint. '
                    .'Returns JSON {passed, errors[], warnings[]} — fix every error before delivering. '
                    .'Caps: 40 files, 200 KB/file, 1 MB total. Parse/lint only — nothing is rendered or stored.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [
                        'files' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                        'mode' => ['type' => 'string', 'enum' => ['files', 'package']],
                    ],
                    'required' => ['files'],
                ],
                // M3-2: parse/lint only — nothing is rendered or stored.
                'annotations' => ['readOnlyHint' => true],
            ],
            // HPMS P2: the outbound webhook event catalog, sourced from
            // WebhookEndpoint::SELECTABLE_EVENTS so it can never drift from what the
            // platform actually fires — including this run's new refund/reservation/
            // customer/shift lifecycle events. Public: only already-documented names.
            DefinedTool::define(
                'list_webhook_events',
                'The authoritative list of outbound webhook event names an integration can subscribe to, '
                .'read from the platform\'s own registry (never stale). Returns the subscribable events '
                .'(plus the `*` wildcard), the delivery-lifecycle events marketplace apps also receive, '
                .'and the `ping` test event. Use this when building a webhook receiver so you match on real '
                .'event names; then fetch_full_doc "api/webhooks-events" for payload shapes and signature '
                .'verification.',
                []
            ),
            // D2-3: agents file doc-gap reports (Mintlify submit-feedback convention).
            // These land in the log as an "unhelpful docs" signal for the docs-gap feed.
            DefinedTool::define(
                'submit_feedback',
                'Report a documentation problem you hit — a missing topic, a wrong or outdated '
                .'statement, or a search that returned nothing useful. Use this when the docs failed '
                .'you so the gap is recorded. Provide `message` (what was wrong or missing) and '
                .'optionally `path` (the doc path it concerns, e.g. "api/webhooks").',
                ['message' => 'string', 'path' => 'string'],
                ['message'],
                // M3-2: a non-destructive write (records a report; not idempotent).
                ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            ),
        ];
    }

    /** Docs tools return markdown, not JSON — the text goes through verbatim. */
    public function run(string $name, array $args): string
    {
        return match ($name) {
            'learn_platform' => $this->learnPlatform($args),
            'get_liquid_reference' => $this->liquidReference($args),
            'get_section_schema' => $this->sectionSchema($args),
            'search_docs' => $this->searchDocs($args),
            'fetch_full_doc' => $this->fetchFullDoc($args),
            'validate_theme' => $this->validateTheme($args),
            'list_webhook_events' => $this->listWebhookEvents(),
            'submit_feedback' => $this->submitFeedback($args),
            default => throw new McpError(-32602, "Unknown tool: {$name}"),
        };
    }

    // --- Tools (spec §2) ---------------------------------------------------

    /**
     * §2.1: primer. Topic maps onto a corpus learn-* doc; default = overview.
     *
     * @param  array<string, mixed>  $args
     */
    private function learnPlatform(array $args): string
    {
        $topic = (string) ($args['topic'] ?? '');

        $doc = match ($topic) {
            '', 'overview' => 'learn-overview',
            'themes' => 'learn-themes',
            'apps' => 'learn-apps',
            'storefront-api', 'customer-api' => 'learn-storefront-api',
            default => throw new McpError(-32602,
                "Unknown topic: {$topic}. Valid: overview, themes, apps, storefront-api, customer-api."),
        };

        $primer = DocsCorpus::get($doc);
        if (! $primer) {
            throw new McpError(-32603, 'Docs corpus is missing — report this to the platform.');
        }

        return $primer['body'];
    }

    /**
     * §2.4: the generated whitelist, rendered as markdown for an LLM consumer.
     *
     * @param  array<string, mixed>  $args
     */
    private function liquidReference(array $args): string
    {
        $kind = (string) ($args['kind'] ?? 'all');
        if (! in_array($kind, ['drops', 'filters', 'tags', 'all'], true)) {
            throw new McpError(-32602, "Unknown kind: {$kind}. Valid: drops, filters, tags, all.");
        }

        $out = ["# Liquid reference (generated from the engine — authoritative)\n"
            ."Anything not listed here does not exist in this engine, regardless of Shopify docs.\n"];

        if ($kind === 'drops' || $kind === 'all') {
            $out[] = "## Drops (objects)\n";
            foreach (DeveloperDocsController::dropReference() as $drop => $props) {
                $out[] = '### '.$drop;
                foreach ($props as $p) {
                    $out[] = "- `{$p['name']}` ({$p['type']})";
                }
                $out[] = '';
            }
        }

        if ($kind === 'filters' || $kind === 'all') {
            $out[] = "## Filters (theme-specific — standard Liquid core filters also apply)\n";
            foreach (DeveloperDocsController::filterReference() as $filter) {
                $out[] = "- `{$filter}`";
            }
            $out[] = '';
        }

        if ($kind === 'tags' || $kind === 'all') {
            $out[] = "## Custom tags (standard Liquid core tags also apply)\n";
            foreach (DeveloperDocsController::tagReference() as $tag) {
                $out[] = "### {% {$tag['name']} %} ({$tag['kind']})";
                $out[] = "Syntax: `{$tag['syntax']}`";
                $out[] = $tag['description'];
                $out[] = "Example:\n```liquid\n{$tag['example']}\n```\n";
            }
        }

        return implode("\n", $out);
    }

    /**
     * §2.5: schema-settings doc; setting_type extracts one type's `##` section.
     *
     * @param  array<string, mixed>  $args
     */
    private function sectionSchema(array $args): string
    {
        $doc = DocsCorpus::get('themes/schema-settings');
        if (! $doc) {
            throw new McpError(-32603, 'Docs corpus is missing — report this to the platform.');
        }

        $settingType = trim((string) ($args['setting_type'] ?? ''));
        if ($settingType === '') {
            return $doc['body'];
        }

        $types = $this->settingTypeSections($doc['body']);
        if (isset($types[$settingType])) {
            return $types[$settingType];
        }

        // The valid list comes from the SAME doc headings the lookup matches,
        // so the message can never drift from what setting_type accepts.
        throw new McpError(-32602,
            "Unknown setting_type: {$settingType}. Valid: ".implode(', ', array_keys($types)).'.');
    }

    /**
     * Setting-type sections of the schema-settings doc: `## <type>` headings
     * ("## checkbox / toggle" covers both) → that section's text. Headings that
     * are prose ("## Schema shape") are not types.
     *
     * @return array<string,string> type → section markdown, in doc order
     */
    private function settingTypeSections(string $body): array
    {
        $types = [];
        foreach (preg_split('/^(?=## )/m', $body) ?: [] as $piece) {
            if (! str_starts_with($piece, '## ')) {
                continue;
            }
            $heading = strtolower(trim((string) strtok(substr($piece, 3), "\n")));
            foreach (array_map('trim', explode('/', $heading)) as $type) {
                if (preg_match('/^[a-z][a-z_]*$/', $type)) {
                    $types[$type] = trim($piece);
                }
            }
        }

        return $types;
    }

    /**
     * §2.2: keyword search over the corpus; results carry the fetch_full_doc hint.
     *
     * @param  array<string, mixed>  $args
     */
    private function searchDocs(array $args): string
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            throw new McpError(-32602, 'query is required.');
        }
        $max = (int) ($args['max_results'] ?? 5);

        // D2-9 M1-2: optional pillar filter. Default (unset/"all") searches every
        // PUBLIC pillar (dev + users/integrations/resellers/shoppers). The private
        // `internal/` pillar is never a valid value — DocsCorpus::pillars() omits it.
        $pillars = null;
        $pillar = strtolower(trim((string) ($args['pillar'] ?? '')));
        if ($pillar !== '' && $pillar !== 'all') {
            if (! in_array($pillar, DocsCorpus::pillars(), true)) {
                throw new McpError(-32602,
                    "Unknown pillar: {$pillar}. Valid: ".implode(', ', DocsCorpus::pillars()).", or 'all'.");
            }
            $pillars = [$pillar];
        }

        $results = DocsCorpus::search($query, $max, $pillars);
        if ($results === []) {
            return "No matches for \"{$query}\". Try broader terms, or fetch_full_doc with one of: "
                .implode(', ', DocsCorpus::paths(['dev']))
                .'. Other public pillars ('.implode(', ', array_values(array_diff(DocsCorpus::pillars(), ['dev'])))
                .') are searchable too — prefix the path, e.g. users/getting-started.';
        }

        $out = ["# Search results for \"{$query}\"\n",
            'Call fetch_full_doc with a `doc` path below for the complete page.', ''];
        foreach ($results as $i => $r) {
            $n = $i + 1;
            $out[] = "## {$n}. {$r['title']} — {$r['section']}";
            $out[] = "doc: `{$r['doc']}` (score {$r['score']})";
            $out[] = '> '.str_replace("\n", "\n> ", $r['snippet'])."\n";
        }

        return implode("\n", $out);
    }

    /**
     * §2.3: whole doc by corpus path; unknown path errors list valid ones.
     *
     * @param  array<string, mixed>  $args
     */
    private function fetchFullDoc(array $args): string
    {
        $path = trim((string) ($args['path'] ?? ''));
        if ($path === '') {
            throw new McpError(-32602, 'path is required.');
        }

        $doc = DocsCorpus::get($path);
        if (! $doc) {
            throw new McpError(-32602,
                "Unknown doc path: {$path}. Valid dev paths: ".implode(', ', DocsCorpus::paths(['dev']))
                .'. Public help pillars are prefixed (users/…, integrations/…, resellers/…, shoppers/…).');
        }

        return $doc['body'];
    }

    /**
     * §2.6: validation with the marketplace's own rules. Parse/lint only.
     *
     * @param  array<string, mixed>  $args
     */
    private function validateTheme(array $args): string
    {
        $files = $args['files'] ?? null;
        if (! is_array($files) || $files === []) {
            throw new McpError(-32602, 'files is required: {"sections/hero.liquid": "<source>", ...}');
        }
        $mode = (string) ($args['mode'] ?? 'files');
        if (! in_array($mode, ['files', 'package'], true)) {
            throw new McpError(-32602, "Unknown mode: {$mode}. Valid: files, package.");
        }

        // Public endpoint: hard input caps BEFORE any work (spec §2.6).
        if (count($files) > 40) {
            throw new McpError(-32602, 'Too many files (max 40 per call).');
        }
        $total = 0;
        foreach ($files as $path => $content) {
            if (! is_string($content)) {
                throw new McpError(-32602, "files['{$path}'] must be a string.");
            }
            if (strlen($content) > 200 * 1024) {
                throw new McpError(-32602, "files['{$path}'] exceeds 200 KB.");
            }
            $total += strlen($content);
            if ($total > 1024 * 1024) {
                throw new McpError(-32602, 'Total input exceeds 1 MB.');
            }
        }

        // Package/layout gates + full lint pipeline, all findings together.
        $errors = [];
        foreach (ThemePackageValidator::lintFileMap($files, $mode) as $f) {
            $errors[] = ['rule' => $f['code'], 'file' => $f['file'], 'message' => $f['message']];
        }
        $lint = (new ThemeLinter)->lintFiles($files);
        $errors = array_merge($errors, $lint['errors']);

        $result = [
            'passed' => $errors === [],
            'errors' => $errors,
            'warnings' => $lint['warnings'],
            'hint' => $errors === []
                ? 'All checks passed. Package-level zip gates still run at submission.'
                : 'Fix every error, then re-validate. Unknown filters/tags? Call get_liquid_reference.',
        ];

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * HPMS P2: the outbound webhook event catalog as markdown, built from the
     * platform's own registry (WebhookEndpoint::SELECTABLE_EVENTS) so it cannot
     * drift. Delivery-lifecycle events (fired by DeliveryService, subscribable by
     * marketplace apps) and the `ping` test event are appended — matching the set
     * DocsPlatformDriftTest treats as "known".
     */
    private function listWebhookEvents(): string
    {
        $selectable = WebhookEndpoint::SELECTABLE_EVENTS;
        // The `*` wildcard is always part of the registry; list the concrete names.
        $subscribable = array_values(array_filter($selectable, fn ($e) => $e !== '*'));
        $lifecycle = ['order.driver_assigned', 'order.out_for_delivery', 'order.delivered', 'order.delivery_failed'];

        $out = ['# Webhook events (generated from the platform registry — authoritative)', ''];
        $out[] = 'Subscribe an endpoint (dashboard **API & Webhooks**, or `POST /api/v1/webhook-endpoints`) '
            .'to any of these names, or `*` for every event. Match on the exact string. '
            .'Payload shapes + signature verification: fetch_full_doc `api/webhooks-events`.';
        $out[] = '';
        $out[] = '## Subscribable events';
        foreach ($subscribable as $event) {
            $out[] = "- `{$event}`";
        }
        $out[] = '';
        $out[] = '## Delivery-lifecycle events (marketplace apps)';
        foreach ($lifecycle as $event) {
            $out[] = "- `{$event}`";
        }
        $out[] = '';
        $out[] = '## Test event';
        $out[] = '- `ping` — sent by the **Test** button; `data.message` only.';

        return implode("\n", $out);
    }

    /**
     * D2-3: record an agent-filed docs-gap report. Logged (not persisted to a
     * table) as the "unhelpful docs" signal for the docs-gap feed; PII-free —
     * message + optional path only, capped so the tool can't be a log-flood vector.
     */
    /** @param  array<string, mixed>  $args */
    private function submitFeedback(array $args): string
    {
        $message = trim((string) ($args['message'] ?? ''));
        if ($message === '') {
            throw new McpError(-32602, 'message is required: describe what was wrong or missing.');
        }
        $message = mb_substr($message, 0, 1000);
        $path = mb_substr(trim((string) ($args['path'] ?? '')), 0, 120);

        \Log::channel(config('logging.default'))->info('[docs-feedback] agent-reported docs gap', [
            'message' => $message,
            'path' => $path !== '' ? $path : null,
        ]);

        return 'Thanks — recorded. Your report'.($path !== '' ? " about `{$path}`" : '')
            .' has been logged for the docs team.';
    }
}
