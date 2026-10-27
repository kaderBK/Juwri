# Voice bridge (P6-09 V-06)

> **Not available on shared hosting** (needs always-on workers; J37/J40). Keep `VOICE_BRIDGE_ENABLED=0` there. Docker and cpanel-vps only.

**JUWRI Cloud only.** The voice bridge is the always-on worker that carries a phone call between the
carrier (Twilio/Telnyx) and `AgentChatService::forVoice`. Community and Enterprise self-host do **not**
get it: it needs always-on WebSocket workers, carrier contracts and per-region media (P6-09 SPEC §10).
The feature is gated on Cloud by the `ai_phone_agent` add-on, re-checked on every call connect.

The bridge is **off by default** (`VOICE_BRIDGE_ENABLED=0`). A self-hosted install that never layers the
compose file keeps answering `200 {"bridge":"disabled"}` at `/healthz/voice-bridge`.

## What runs

| Role | Compose service | Command | Ports |
|---|---|---|---|
| Bridge Octane worker | `bridge` | `frankenphp run …` (Laravel Octane) | `${VOICE_BRIDGE_PORT:-8083}` → container `8080` |
| Bridge Reverb WebSocket | `bridge-reverb` | `php artisan reverb:start` | `${VOICE_BRIDGE_REVERB_PORT:-8082}` |

Both reuse the `juwri/app` image. The worker is kept separate from the web nodes so a call that lives for
minutes never competes with short storefront requests for an Octane worker. Reverb runs on its **own**
port, never the app's live-updates WebSocket.

## Deploy

```bash
# on juwri Cloud, from the repository root:
docker compose -f docker/compose.prod.yml -f docker/compose.voice-bridge.yml --env-file docker/.env up -d
```

Set in `docker/.env` (see `docker/env.example`):

| Var | Meaning |
|---|---|
| `VOICE_BRIDGE_ENABLED` | `1` to turn the role on (required by the compose file for `bridge`). |
| `VOICE_BRIDGE_PUBLIC_URL` | Public **wss** origin handed to the carrier, e.g. `https://bridge.juwri.com`. Required. |
| `VOICE_BRIDGE_REVERB_PORT` | Bridge Reverb port (default `8082`). |
| `VOICE_BRIDGE_PORT` | Published bridge worker port (default `8083`). |
| `VOICE_BRIDGE_WORKERS` | Octane worker count inside `bridge` (default `8`). |
| `VOICE_BRIDGE_REPLICAS` | `bridge` replicas (default `1`). |
| `VOICE_BRIDGE_MAX_CONCURRENT_CALLS` | Per-line concurrent-call cap (default `3`). |
| `VOICE_BRIDGE_SECRET` | Optional HMAC key for the per-call bridge token; empty = derive from `APP_KEY`. |
| `VOICE_BRIDGE_PROBE_TIMEOUT` | Seconds for the health probe's Reverb dial (default `1.5`). |

`VOICE_BRIDGE_PUBLIC_URL` is read as `services.telephony.ws_base` by `VoiceWebhookController`; the carrier's
answer markup points callers at `wss://<VOICE_BRIDGE_PUBLIC_URL>/voice/<provider>/bridge/<line>?t=<token>`.

## TLS (wss)

Terminate TLS at the proxy in front of the bridge (Caddy/ALB/nginx). Publish `VOICE_BRIDGE_PORT` and
`VOICE_BRIDGE_REVERB_PORT` and expose them as **wss**; carriers refuse plain `ws://` for a production
number. Never expose the Reverb port directly to browsers.

## Health

`GET /healthz/voice-bridge` (public, its own throttle bucket, sat next to `/healthz`):

```json
{"ok": true, "bridge": "enabled", "reverb": true, "octane": true}
```

| Condition | Status | Body |
|---|---|---|
| Bridge disabled (default) | `200` | `ok:true, bridge:"disabled", reverb:false` |
| Enabled, Reverb reachable | `200` | `ok:true, bridge:"enabled", reverb:true` |
| Enabled, Reverb unreachable | `503` | `ok:false, bridge:"enabled", reverb:false` |

`octane` reports whether the process is an Octane worker. The payload is four fixed keys — no secret and
no tenant data is ever read or echoed. The compose healthcheck calls this endpoint, so a `bridge` replica
whose Reverb sidecar cannot be dialled is marked unhealthy and rotated out.

## Scaling

Scale by the **per-line concurrent-call cap** (default **3 calls per line**, `VOICE_BRIDGE_MAX_CONCURRENT_CALLS`):
calls past the cap get `unavailable()`/handoff, never a silent drop (P6-09 SPEC §6). Raise the cap only with
enough Octane workers and Reverb capacity to hold the extra long-lived sockets; add `bridge` replicas
(`VOICE_BRIDGE_REPLICAS`) and Reverb capacity together. Per-call limits (8 min, 20 agent turns) and the
per-store voice-minute cap still apply.
