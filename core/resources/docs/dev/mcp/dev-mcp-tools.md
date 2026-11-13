---
title: "Dev MCP server tools"
audience: developer
group: Mcp
updated: 2026-09-10
---

# Dev MCP server tools

The Dev MCP server at `POST /mcp/dev` is a public, read-only Model Context
Protocol endpoint for AI coding tools (Claude Code, Cursor, VS Code Copilot).
It serves the same information already published on `/developers` and
`/llms.txt` — platform primers, the generated Liquid whitelist, the section
schema spec, the developer docs corpus, and server-side theme validation. No
authentication, no tenant or store data, ever. It is a separate server from
the authenticated admin MCP at `POST /mcp`; the two share only the JSON-RPC
envelope.

Implementation: `DevMcpController` (route name `mcp.dev`), docs loading in
`App\Services\DevDocs\DocsCorpus`. See [Connect your AI editor](connect-editor.md)
for the copy-paste editor configs.

## Protocol and limits

- JSON-RPC 2.0 over Streamable HTTP POST, default MCP protocol version
  `2025-06-18`, server name `juwri-dev-mcp` (on a white-label domain,
  the reseller's brand slug replaces the platform prefix) version `1.0.0`. The server
  also negotiates `2025-03-26` and `2024-11-05`.
- Supported methods: `initialize`, `ping`, `tools/list`, `tools/call`. Batch
  requests (a JSON array body) are handled; accepted `notifications/*`
  messages return HTTP 202 with no body. Unknown methods return JSON-RPC error
  `-32601`, bad tool arguments `-32602`, internal failures `-32603`.
- Send the negotiated version in `MCP-Protocol-Version` after initialization
  and prefer `Accept: application/json, text/event-stream`. Responses are
  JSON-only; `GET` returns 405 because this deployment does not expose SSE.
- Browser requests must be same-origin or match an exact
  `MCP_ALLOWED_ORIGINS` entry. CLI/editor clients normally omit `Origin`.
- Rate limit: 60 requests/min per IP (`throttle:60,1`). `validate_theme` adds
  its own input-size caps (below). The route is CSRF-exempt.
- Every tool returns a single `content` item of type `text`. Docs tools return
  markdown; `validate_theme` returns pretty-printed JSON as text.

Quick smoke test:

```bash
curl -s https://<site>/mcp/dev \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2025-06-18' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## learn_platform

The recommended first call in any session. Returns a markdown primer: the three
developer surfaces (Liquid themes, Storefront API, Customer API), key
endpoints, hard rules agents commonly get wrong, and which tool to use next.

- Input: `topic` (optional string) — `overview` (default), `themes`, `apps`
  (build & sell a marketplace app — OAuth, scopes, session tokens, webhooks,
  usage billing), `storefront-api`, `customer-api`. Unknown topics error
  listing the valid set.
- Output: the matching `learn-*.md` corpus doc verbatim (`storefront-api` and
  `customer-api` share one primer).

## get_liquid_reference

The authoritative whitelist of Liquid the theme engine supports. Anything not
listed — a Shopify filter, an invented drop property — fails validation.

- Input: `kind` (optional string) — `drops`, `filters`, `tags`, or `all`
  (default).
- Output: markdown with drop objects and their properties, the theme-specific
  filter list (standard Liquid core filters also apply), and custom tags
  (`schema`, `commerce`) with syntax and examples.

The reference is generated at request time by reflection over the engine's own
classes (`DeveloperDocsController::dropReference()`, `::filterReference()`,
`::tagReference()`), so it can never be stale — see drift-pinning below.

## get_section_schema

The `{% schema %}` format for theme sections: JSON shape, every supported
setting type with its exact validation rule, blocks, `templates/index.json`
wiring, and a worked example from the starter theme.

- Input: `setting_type` (optional string) — returns just that type's rules,
  e.g. `color` or `select`. Valid types: `text`, `textarea`, `color`,
  `checkbox`, `toggle`, `select`, `image` (checkbox and toggle share a
  section). Unknown types error listing the valid set.
- Output: the [themes/schema-settings](../themes/schema-settings.md) corpus doc
  (or the one matching `##` section of it).

## search_docs

Keyword search across the whole developer docs corpus — theme format, APIs,
webhooks, validation, editor setup. The broad research tool when unsure where
to look.

- Input: `query` (string, required), `max_results` (integer, default 5, max
  10).
- Output: markdown results, one per chunk, each with the doc title, the `##`
  section heading it came from, its corpus path (for `fetch_full_doc`), a
  relevance score, and a snippet of up to 400 characters.

Docs are chunked on `##` headings. Scoring per query term: doc-title hit ×3,
section-heading hit ×2, body occurrences ×1 (capped at 5 per chunk); chunks
matching more distinct terms always rank above chunks repeating one term. No
match returns a hint listing every valid corpus path.

## fetch_full_doc

Fetch one complete doc by corpus path after `search_docs` finds it.

- Input: `path` (string, required) — a corpus-relative path without extension,
  e.g. `themes/validation`, `api/webhooks`, `learn-overview`.
- Output: the full markdown doc. Unknown paths error listing every valid path,
  so a wrong guess is self-correcting.

## validate_theme

Server-side validation of theme files using the same rules the marketplace
applies at submission: strict whitelist Liquid parse, unknown filters/tags,
missing snippets, external URLs, schema shape, dangerous markup, and
layout/extension rules. Parse and lint only — nothing is rendered or stored.

- Input: `files` (object, required) mapping paths to source, e.g.
  `{"sections/hero.liquid": "<source>"}`; `mode` (optional) — `files`
  (default) lints fragments, `package` additionally enforces package-level
  gates (`theme.json` manifest, templates entrypoint) via
  `ThemePackageValidator::lintFileMap()`.
- Caps, enforced before any work: 40 files, 200 KB per file, 1 MB total.
  Exceeding a cap returns a clean `-32602` error, not a 500.
- Output: JSON text `{passed, errors[], warnings[], hint}`. Errors carry
  `rule`/`code`, `file`, and `message`; unknown-filter errors point back to
  `get_liquid_reference`. Even a clean pass notes that package-level zip gates
  still run at real submission.

See [themes/validation](../themes/validation.md) for the full rule list and
[themes/submission](../themes/submission.md) for the submission flow.

## Drift-pinning: why the reference cannot go stale

Three mechanisms keep tool output equal to what the engine actually accepts:

1. Request-time reflection. `get_liquid_reference` is generated per request
   from the engine's own classes: drops from `app/Storefront/Drops/*` (public
   zero-arg methods, minus base-class and `Hidden`-attributed ones), filters
   from `ThemeFilters` by the registry's own rule, tag names from each tag
   class's `tagName()`. New engine capability appears in the tool with no doc
   step.
2. Generated corpus files. `php artisan docs:build-reference` writes
   `themes/drops.md`, `themes/filters.md`, and `themes/tags.md` from the same
   reflection so `search_docs` finds them too. The `--check` flag diffs against
   the committed files and exits non-zero on drift; the test suite runs it
   (`DevMcpReferenceTest::test_generated_corpus_docs_are_current`), so a stale
   commit fails CI.
3. Pinning tests. Feature tests assert every whitelisted filter, every drop
   property, and every registered tag appears in the tool output, and that the
   schema doc's setting types match the customizer's accepted-type list
   exactly (`core/tests/Feature/DevMcpReferenceTest.php`).

## Example agent session

A typical theme-building conversation, as the tool descriptions steer it:

1. `learn_platform {"topic": "themes"}` — the agent gets the theme package
   format, workflow, and the instruction not to answer platform questions from
   memory.
2. `get_liquid_reference {"kind": "filters"}` — confirms `money` exists and,
   say, Shopify's `img_url` does not.
3. `get_section_schema {"setting_type": "select"}` — the exact validation rule
   for a select setting before writing the schema block.
4. The agent writes `sections/hero.liquid`.
5. `validate_theme {"files": {"sections/hero.liquid": "..."}}` — returns
   `{"passed": false, "errors": [...]}` naming an unknown filter; the hint
   says to check `get_liquid_reference`.
6. Fix, re-validate until `passed: true`, deliver. Package-level zip gates
   still run at submission.

When the agent is unsure where something is documented, it interleaves
`search_docs {"query": "webhook signature"}` and follows the returned `doc`
path with `fetch_full_doc`.

## Related pages

- [Connect your AI editor](connect-editor.md) — endpoint configs for Claude
  Code, Cursor, and VS Code.
- [Theme validation rules](../themes/validation.md) and
  [submission](../themes/submission.md).
- [Liquid drops](../themes/drops.md), [filters](../themes/filters.md), and
  [tags](../themes/tags.md) — the generated corpus copies of the reference.
- [MCP servers overview](mcp-servers.md) — the Dev server documented here is
  public and tenant-data-free; an agent that needs a specific store's own
  catalog (including the `store_type`/vertical-scoped brand, variant,
  shipment, fitment and pharmacy-compliance tools) uses the authenticated
  **Admin MCP** instead.
