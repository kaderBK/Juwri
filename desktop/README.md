# juwri Desktop — shell + LAN print bridge

The desktop app is two things in one process:

1. an **Electron shell** that wraps the existing POS web app (no POS code is
   reimplemented here — it loads your store's own site), and
2. the **LAN print bridge**: a small HTTP server on `http://127.0.0.1:8543` that
   the page calls to reach `tcp://<ip>:9100` kitchen printers.

The second one is the reason the app exists. **A browser cannot open a raw TCP
socket**, so no web page can ever talk to a network thermal printer directly.
Web Serial / WebUSB (in the browser) cover the printer plugged into the till, and
RawBT covers Android; everything else — network printers, multi-station routing,
silent auto-print — needs a local process, and this is it.

The HTTP surface is frozen in [`rev/parity/contracts/print-bridge.md`](../rev/parity/contracts/print-bridge.md)
and is implemented here **verbatim**. The web half (T1) is already shipped and
merged against that contract, so this side may not drift from it.

---

## Install (operator)

Until signed installers exist (see *Deferred* below), run it from source:

```bash
cd desktop
npm install          # Node 18+; installs Electron for your platform
npm start
```

First launch asks for your **store address** — the same URL you use to sign in,
e.g. `https://menu.example.com`. That address is used twice: it is the page the
shell loads, **and** it becomes the CORS allowlist the bridge enforces. No other
site can print.

## Pairing (once per till)

1. In the dashboard: **Settings → Printing → Desktop app pairing → Generate**.
2. Copy the 40-character code.
3. In the desktop app: **File → Desktop settings… → Pairing**, paste, press
   **Pair**.

The code is stored encrypted by your operating system's keychain (macOS Keychain
/ Windows DPAPI) when one is available; the settings window tells you which of the
two applies on this machine.

Rotating the code in the dashboard instantly unpairs every desktop app — that is
the merchant's revoke button.

## Adding printers

**Desktop settings → Printers → Add printer**

| Field | Example | Notes |
|---|---|---|
| Name | `Kitchen` | Shown in `/health` and in the dashboard's printer list. |
| Address | `tcp://192.168.1.50:9100` | Or `usb://0x04b8/0x0e15`. Port defaults to 9100. |
| Paper width | 80 mm | 80 or 57 only — it must match the width the server renders for. |

**Test print** sends a canned ESC/POS ticket straight to the printer, bypassing
HTTP, the token and the web app entirely. If the test ticket prints but real
tickets do not, the problem is pairing or the dashboard's printer profile — not
the cable.

A printer must be added here before it can be used: a job for an address that is
not configured is refused with `unknown_target`. This is deliberate — otherwise
the bridge would be a "write arbitrary bytes to any host on this network"
primitive for whatever page holds the token. If the register asked for a printer
you have not added, the Printers tab offers it to you with an **Add** button.

---

## What the bridge does

```
GET  http://127.0.0.1:8543/health   → { ok, version, printers:[{id,label,kind,status}] }
POST http://127.0.0.1:8543/print    → { ok:true, jobId }  |  { ok:false, jobId, error, detail }
```

Both require `Authorization: Bearer <pairing token>`.

| Behaviour | Rule |
|---|---|
| Binding | `127.0.0.1` only. Never `0.0.0.0`. |
| Port | Fixed at **8543**. If it is taken the bridge refuses to start and says so — it never moves to another port, because the web side has no discovery and would simply lose printing. |
| Token | Compared in constant time. No token configured ⇒ `409 unpaired`; wrong token ⇒ `401 unauthorized`. |
| CORS | An allowlist of your store's origin(s). Never `*`. A foreign origin is refused by the server, not merely denied the response header. |
| Idempotency | The last 200 `jobId`s are remembered for ~10 minutes. Re-sending a `jobId` that already succeeded returns the original `{ok:true}` **without printing again** — this is what makes the register safe to retry after a timeout, and what stops the kitchen getting duplicate tickets. |
| Timeouts | 5 s to connect, 10 s to write. |
| Errors | A closed set: `unauthorized`, `unpaired`, `bad_request`, `unknown_target`, `econnrefused`, `etimedout`, `device_busy`, `payload_too_large`, `internal`. |
| Status codes | Reserved for protocol problems: `400` malformed, `401` bad token, `409` unpaired, `413` too large, `500` crash. **Printer problems are HTTP 200 with `ok:false`**, so the register can show a reason instead of retrying forever. |
| Payload | `dataB64` ≤ 512 KB decoded — raw ESC/POS bytes rendered by the server. |
| Logging | Job ids, targets and outcomes. **Never** payloads or tokens. |

### Private Network Access

Modern Chrome preflights any request from a public HTTPS page to a loopback
address and requires `Access-Control-Allow-Private-Network: true` in the answer.
The frozen contract predates that requirement; the bridge emits the header **only**
for an origin that is already on the allowlist, so it widens nothing. This is the
one place where the implementation adds a header the contract does not list, and
without it the contract could not function in a current browser at all.

---

## Offline

The POS web app already runs offline (service-worker shell + a queued outbox).
The desktop shell just loads it, so nothing changes there — and **bridge printing
keeps working with no internet at all**, because the till, the bridge and the
printer are all on the same LAN. An offline order that is queued in the browser
prints its kitchen ticket immediately; the server sync happens later.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Settings shows *“Port 8543 is already in use”* | A second copy of the app, or another program, holds the port. | Quit the other copy and restart. The port is fixed by the contract. |
| Dashboard says *“pair the desktop app”* | The bridge has no token (`409`). | Paste the pairing code in Desktop settings → Pairing. |
| Dashboard says the token was rejected (`401`) | The code was rotated in the dashboard, or pasted with a typo/whitespace. | Generate a fresh code and paste it again. |
| Printing works from the desktop app's *Test print* but not from the register | The register's printer profile points at a different address, or the store origin is not allowlisted. | Compare the address in **Settings → Printing** with the one in Desktop settings → Printers. Check the *Allowed store addresses* box. |
| `unknown_target` | The address the register asked for is not configured here. | Add it in Desktop settings → Printers (the tab offers it in one click). |
| `econnrefused` | Printer off, wrong IP, or a different subnet / guest Wi-Fi. | Ping the printer from the till; most ESC/POS printers self-print their IP on a feed-button power-up. |
| `etimedout` | Printer reachable but not answering — usually out of paper, cover open, or asleep. | Check the printer, then retry; the same `jobId` is safe to retry. |
| Nothing prints and nothing errors | The register is not using the `bridge` transport for that profile. | In **Settings → Printing** set the profile's transport to *Network printer (desktop app)*. |
| The whole site fails to load | Wrong store address. | Desktop settings → Application → Store address. |

Logs go to the process's stdout/stderr (`npm start` shows them). They contain
job ids and outcomes only.

---

## Developing

```bash
npm test          # the bridge contract suite — no printer, no Electron needed
npm start         # run the shell
npm run bridge    # run ONLY the bridge, headless:
                  #   BRIDGE_TOKEN=… BRIDGE_ORIGIN=https://menu.example.com \
                  #   BRIDGE_PRINTERS='[{"id":"tcp://192.168.1.50:9100","label":"Kitchen"}]' npm run bridge
npm run pack      # unsigned local build (electron-builder --dir)
npm run dist:mac  # unsigned dmg
npm run dist:win  # unsigned nsis
```

`electron . --smoke-exit` boots the whole shell, starts the bridge, loads the
settings and first-run windows offscreen, drives the preload/IPC path, checks the
Arabic/RTL lane, and quits with status 0 — that is what CI runs. It catches the
class of defect a request-level suite cannot: a settings window that renders blank
is an app that boots perfectly and can never be paired.

### Shell behaviour worth knowing

- The wrapped site gets **no preload at all** — `contextIsolation`, `sandbox`, no
  Node. It is treated as untrusted code, and it reaches the bridge the same way
  any browser would: HTTP with a bearer token.
- Navigation stays in-window for the store and for any `https` page (a card
  gateway redirect must not be bounced into another browser); `window.open` links
  and non-https targets go to the system browser.
- Camera, microphone, geolocation and other powerful permissions are **denied** —
  the POS needs none of them on a shared till. Denials are logged, so if a page
  genuinely needs one it is visible rather than mysterious.
- A second copy of the app refuses to start: it would lose the race for port 8543
  and leave the operator looking at a window whose printing silently does not work.

### Layout

```
src/bridge/      the contract. Plain Node, no Electron import — that is what makes it testable.
  server.js        routing, auth, CORS, validation, idempotency, the closed error vocabulary
  auth.js          constant-time bearer comparison
  cors.js          origin allowlist
  jobs.js          the 200-id / 10-minute ledger + in-flight coalescing
  targets.js       tcp:// and usb:// parsing
  errors.js        the closed vocabulary and the socket-error mapping
  transport/       network.js (node-escpos Network adapter + timeouts), usb.js (optional)
src/main/        Electron: windows, config, i18n, bridge lifecycle, canned test ticket
src/renderer/    the local setup + settings windows (file://, CSP-locked, no network)
src/locales/     the shell's own i18n — EN + AR. NOT core/lang.
test/            the suite below
```

### What the tests cover

`npm test` runs 72 checks over real HTTP against the real server object, with the
printer faked two ways: a recording transport (so "printed exactly once" is an
assertion, not an inference) and an actual TCP server on loopback driven through
the real `escpos-network` adapter.

- auth: unpaired ⇒ 409, missing/short/long/wrong-case token ⇒ 401, never prints
- CORS: allowlist echoed and never `*`, preflight headers, Private Network Access,
  a foreign origin refused *with a valid token*, look-alike origins (scheme, port,
  suffix), and a no-Origin caller allowed (that is the server-side client)
- `/health`: exact shape, `unknown` status by default, online/offline learned from
  real jobs, loopback-only binding, and a port clash failing loudly
- `/print`: 16 malformed-body cases ⇒ 400, unknown target ⇒ 200 + `unknown_target`,
  512 KB boundary in both directions, oversized raw body refused unbuffered,
  socket-error → vocabulary mapping, bytes forwarded unmodified
- idempotency: replay returns the original answer with **one** transport call,
  a changed payload under a used jobId still replays, different jobIds both print,
  a *failed* job is retried for real, a retry landing mid-write shares the attempt,
  200-id eviction and the 10-minute TTL
- transport: exact bytes on a real socket, connection refused, connect timeout,
  write timeout, a late socket error not re-settling a finished job, per-printer
  serialisation, queue saturation ⇒ `device_busy`
- config: allowlist derived from the paired URL, token round-trip, encrypted at
  rest, never leaked to a renderer, printers keyed canonically
- conformance: the contract's literal example request/response, and proof that
  neither the token nor the ticket bytes are ever logged

### What genuinely cannot be tested here

Everything above the socket is covered. These need hardware:

- a real ESC/POS printer accepting the bytes (paper width, cut, drawer kick,
  Arabic raster output) — the bridge is byte-transparent, so this validates
  `EscPosRenderer`, not the bridge;
- **USB printing**: needs `escpos-usb` + libusb rebuilt for Electron's ABI, plus a
  WinUSB driver binding on Windows. Install with `npm run add:usb`. Absent, USB
  targets fail with `internal` / `usb_driver_unavailable` and every other lane is
  unaffected;
- printer-specific quirks (Star vs Epson raster, buffer-full back-pressure);
- signed/notarised installers and auto-update.

---

## Deferred (owner action)

| Item | Why | Needs |
|---|---|---|
| Code signing + notarisation | Unsigned builds warn on macOS Gatekeeper and Windows SmartScreen | Apple Developer ID + a Windows OV/EV certificate |
| Auto-update channel | No release feed exists | A hosting decision (GitHub Releases / S3) then `electron-updater` |
| Download link in the dashboard's printing settings | There is no artifact URL to link to yet | The above; then one block on T1's printing page |
| Linux builds | Not requested; the shell is platform-neutral | A target decision (AppImage/deb) |
| App icon | Packaging falls back to the default Electron icon | A 512×512 PNG/ICNS/ICO dropped in `desktop/build/` |
| Physical printer verification | No hardware in this environment | One 80 mm network printer on the LAN |
| USB lane verification | Same, plus per-OS driver setup | An Epson/Star USB printer |
