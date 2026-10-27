---
title: "Connect your AI editor"
audience: developer
group: Mcp
updated: 2026-07-29
---

# Connect your AI editor

Point your AI coding tool at the platform's Dev MCP server and it gets live,
always-current platform docs and validation — no more hallucinated filters or
endpoints.

## Endpoint

`POST <site>/mcp/dev` — public, read-only, JSON-RPC 2.0 (MCP Streamable HTTP
transport), rate-limited 60 req/min. The default protocol version is
`2025-06-18`.

## Claude Code

```bash
claude mcp add --transport http platform-dev <site>/mcp/dev
```

## Cursor

`.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "platform-dev": { "url": "<site>/mcp/dev" }
  }
}
```

## VS Code (Copilot MCP)

`.vscode/mcp.json`:

```json
{
  "servers": {
    "platform-dev": { "type": "http", "url": "<site>/mcp/dev" }
  }
}
```

## Tools you get

- `learn_platform` — start here; primer + routing to the right tool.
- `search_docs` / `fetch_full_doc` — search the developer docs corpus.
- `get_liquid_reference` — the generated, authoritative Liquid whitelist.
- `get_section_schema` — `{% schema %}` format + setting types.
- `validate_theme` — server-side lint of your theme files before submission.

## Other MCP servers

The platform exposes four additional MCP servers beyond the Dev MCP documented
above. All use the same JSON-RPC 2.0 / MCP HTTP transport.

| Server | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| Owner/Admin MCP | `POST <site>/mcp` | API token (Bearer) | Full restaurant management — menu, orders, customers, analytics, coupons, settings. |
| Catalog MCP | `POST <site>/mcp/catalog` | None (public) | Cross-store discovery — search stores and menu items platform-wide. |
| Customer MCP | `POST <site>/mcp/customer` | Customer token | Diner account — order history, tracking, reorder. |
| Storefront MCP | `POST <site>/mcp/storefront` | Storefront agent token | Per-restaurant ordering agent — browse menu, validate cart, place orders. |

See [mcp-servers.md](mcp-servers.md) for full details on each server's tools
and authentication.

## Transport and browser-origin requirements

MCP-aware editors negotiate the protocol automatically. A manual client
should send `MCP-Protocol-Version: 2025-06-18` after initialization and use
`Accept: application/json, text/event-stream`. This server returns JSON over
POST; it has no resumable SSE GET endpoint (`GET` returns 405).

CLI and editor clients normally omit `Origin`. Browser-based MCP clients must
be same-origin. To allow a trusted cross-origin browser client, set an exact
comma-separated allowlist and rebuild the configuration cache:

```dotenv
MCP_ALLOWED_ORIGINS=https://agent.example,https://agent.example:8443
```

Wildcards are intentionally unsupported. A present, unapproved `Origin`
returns HTTP 403.

## Recommended agent workflow

`learn_platform` → build → `validate_theme` → fix → deliver. Never ship a
file that hasn't passed validation.
