---
title: "AI phone agent (Twilio / Telnyx)"
audience: owner
group: Integrations
updated: 2026-07-25
---

# AI phone agent (Twilio / Telnyx)

An AI voice agent answers the restaurant's phone line and can take orders. Owner
screen: **Dashboard → Phone agent** (`/phone-agent`). The whole route group is behind
`feature:ai_phone_agent` — the landing page, the save, the toggle and the call-detail
endpoint.

## Configure a line

`POST /phone-agent` :

| Field | Required | Notes |
|---|---|---|
| `provider` | yes | `twilio` or `telnyx` |
| `number` | yes | the BYOK number the provider will dial us on |
| `greeting` | no | spoken welcome; a default is used when blank |
| `handoff_number` | no | dialled when the agent cannot serve the call |
| `orders_enabled` | no | whether the agent may take orders (default on) |
| `auth_token` | no | provider auth token — stored encrypted under `feature` = provider |

A line can be enabled/disabled with `POST /phone-agent/{id}/toggle`.
`GET /phone-agent/call/{id}` returns one call's JSON (caller, outcome, linked order,
duration, transcript).

## Inbound-call webhook

`POST /voice/{provider}/incoming` — rate-limited, CSRF-exempt. Point the provider's
voice webhook for the number at this URL.

1. The provider is resolved from the registry (`twilio`, `telnyx`, `fake`); unknown → 404.
2. The tenant is resolved from the **dialled** number (Twilio `To`/`Called`,
   Telnyx `To`/`to`); no matching line → 404.
3. The signature is verified; failure → 401.
4. Entitlement is re-checked at serve time via `FeatureAccess::storeAllows(…,
   'ai_phone_agent')` for the line's store owner. This is deliberately *not* route
   middleware — a provider POST has no session user, so `feature:` would 403 every call.
   It fails closed.
5. A refusal is a **graceful 200 answer**, never a non-2xx: the line's `handoff_number`
   is dialled, or a short spoken apology plays and the call hangs up. A non-2xx would
   just make the provider retry and drop a live caller.

On success the call is answered by connecting the audio to our voice bridge WebSocket
at `{ws_base}/voice/{provider}/bridge/{lineId}` — `ws_base` comes from
`services.telephony.ws_base`, defaulting to the app URL with `wss://`.

## Call-end callbacks

Saving a line also points the provider's call-end callback at `POST /voice/{provider}/status`
(Twilio: the number's `StatusCallback`; Telnyx: the TeXML application's `status_callback`).
Re-saving is safe (same URL is re-applied). It needs provider API credentials on the form:
Twilio `account_sid` + `auth_token`; Telnyx `api_key` + `connection_id` (the TeXML application id).
Without them the line still saves and you set the callback URL by hand. Override the base URL
with `services.telephony.status_base` when the public host differs.

For Telnyx webhook signature checks set `TELNYX_PUBLIC_KEY` (base64 Ed25519 public key from the
Telnyx portal, Keys and Credentials, Public Key) in `.env`, or save it per store as `public_key`.

## Signature verification

- **Twilio**: `X-Twilio-Signature` = `base64(HMAC-SHA1(fullUrl + sorted key.value POST
  params, authToken))`, keyed with the stored `auth_token`. No token → reject.
- **Telnyx**: experimental. Reads `Telnyx-Signature-Ed25519` (or `X-Telnyx-Signature`)
  and currently checks a placeholder `HMAC-SHA256` over the raw body with the stored
  `signing_secret` (or `auth_token`). The real Telnyx scheme is Ed25519 over
  timestamp + body with their public key — wired when live credentials land. No secret
  → reject.

## Answer payloads

Twilio answers with TwiML `<Connect><ConversationRelay …>` (Twilio does STT/TTS with
barge-in; our bridge exchanges text — ttsProvider ElevenLabs, transcriptionProvider
Deepgram, `interruptible="speech"`). Telnyx speaks TeXML, a TwiML-compatible dialect,
so the answer mirrors the same shape.

## Status

Twilio is the reference driver. The Telnyx driver is an EXPERIMENTAL stub that proves
the provider abstraction is not Twilio-shaped; its live number and real signature
verification are deferred.
