# MCP Registry publish runbook (owner action)

**Status: OWNER ACTION (DEC-10).** `deploy/mcp/server.json` is authored and
committed. Actually publishing to the official MCP registry
(`registry.modelcontextprotocol.io`) requires proving control of the domain in
the namespace, which only the owner can do. This run does not (and cannot) run
the publish step.

Only the two **public** servers are listed — Catalog and Dev. The token-auth
servers (Admin, Storefront, Customer) are excluded from v1: the registry is for
servers a client can connect to without first obtaining credentials.

## 0. Substitute your real domain first

`server.json` uses `juwri.com` as a placeholder in both the namespace
(`com.juwri/...`) and the `remotes[].url`. Replace **both** with the exact
domain you will prove ownership of. The namespace host and every `remotes` URL
host must be the same domain, and it must serve the live endpoints
(`/mcp/catalog`, `/mcp/dev` respond to `POST` today; `GET` shows the landing page).

## 1. Install the publisher

```
# Homebrew
brew install mcp-publisher
# or download a release binary from
# https://github.com/modelcontextprotocol/registry/releases
```

## 2. Prove domain ownership

Pick ONE challenge (the CLI walks you through it):

- **DNS**: add the TXT record the CLI prints to `juwri.com` (or your
  chosen domain), then continue.
- **HTTP**: host the file the CLI prints at
  `https://<domain>/.well-known/mcp-registry-auth`.

```
mcp-publisher login dns   --domain <your-domain>   # or: login http --domain <your-domain>
```

## 3. Validate + publish (one server per call)

`mcp-publisher` publishes a single server descriptor per invocation. Split the
two entries in `server.json` into one file each (e.g. `catalog.json`, `dev.json`
— copy the object out of the `servers` array, keep the `$schema` key), then:

```
mcp-publisher validate catalog.json && mcp-publisher publish catalog.json
mcp-publisher validate dev.json     && mcp-publisher publish dev.json
```

(If the current CLI supports a multi-server manifest directly, you can point it
at `server.json` as-is — check `mcp-publisher publish --help`.)

## 4. Verify

- The servers appear at `https://registry.modelcontextprotocol.io` under the
  `com.<your-domain>/*` namespace.
- Our own discovery manifest (`https://<domain>/.well-known/mcp.json`) already
  advertises all five servers for clients that discover via the site.

## Notes / follow-ups

- **Anthropic Connectors Directory** is a SEPARATE bar (OAuth 2.1 + PKCE + tool
  annotations + public privacy policy). Tool annotations shipped (M3-2); OAuth is
  the remaining gap (M3-3) — a separate,
  owner-approved run.
- Re-run the publish whenever `version` changes in a server descriptor.
